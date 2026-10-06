<?php

namespace App\Services\Clinic;

use App\Enums\AppointmentStatus;
use App\Enums\EncounterStatus;
use App\Enums\LiveQueueStatus;
use App\Enums\PaymentStatus;
use App\Events\LiveQueueUpdated;
use App\Events\NextPatientCalled;
use App\Events\PublicNextPatientCalled;
use App\Events\QueueReordered;
use App\Helpers\ShiftHelper;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\LiveQueue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LiveQueueService
{
    /**
     * جلب الطابور الحي للفرع بناءً على شفت اليوم الطبي الشغال حالياً
     */
    public function getQueueForBranch(string $branchId, int|string|null $doctorId = null)
    {
        [$startTime, $endTime] = ShiftHelper::getShiftWindow();

        $query = LiveQueue::where('branch_id', $branchId)
            ->with(['patient', 'doctor', 'appointment'])
            ->whereBetween('created_at', [$startTime, $endTime])
            ->whereIn('status', LiveQueueStatus::activeStatuses());

        if (! empty($doctorId)) {
            $query->where('doctor_id', $doctorId);
        }

        return $query->orderBy('queue_no', 'asc')->get();
    }

    /**
     * إدراج مريض داخل طابور الانتظار الحي (Check-In)
     */
    public function createNewPatientInQueue(array $data, string $branchId): LiveQueue
    {
        return DB::transaction(function () use ($data, $branchId) {
            [$startTime, $endTime] = ShiftHelper::getShiftWindow();
            $shiftDate = Carbon::parse($startTime)->toDateString();
            $doctorId = $data['doctor_id'] ?? null;

            // قفل صف الفرع لمنع الـ Deadlocks
            DB::table('branches')->where('id', $branchId)->lockForUpdate()->get();

            // 🎯 حساب رقم الدور الخاص بالطبيب داخل شفت اليوم (لكل دكتور طابوره المستقل)
            $maxQueueQuery = LiveQueue::where('branch_id', $branchId)
                ->where(function ($q) use ($shiftDate, $startTime, $endTime) {
                    $q->where('shift_date', $shiftDate)
                        ->orWhereBetween('created_at', [$startTime, $endTime]);
                });

            if ($doctorId) {
                $maxQueueQuery->where('doctor_id', $doctorId);
            }

            $maxQueueNo = $maxQueueQuery->max('queue_no') ?? 0;

            $queueItem = LiveQueue::create([
                'branch_id' => $branchId,
                'doctor_id' => $doctorId,
                'shift_date' => $shiftDate,
                'patient_id' => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'queue_no' => $maxQueueNo + 1,
                'checked_in_at' => now()->toTimeString(),
                'status' => LiveQueueStatus::CHECKED_IN->value,
            ]);

            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in createNewPatientInQueue: '.$e->getMessage());
                }
            });

            return $queueItem;
        });
    }

    /**
     * تسجيل مريض مباشر (Walk-In) وإدراجه في الطابور الحي مباشرة بدون Appointment وبدون فاتورة
     */
    public function checkInWalkIn(array $data, string $branchId): LiveQueue
    {

        return DB::transaction(function () use ($data, $branchId) {
            $patientId = $data['patient_id'] ?? null;
            if (! $patientId) {
                $patientService = app(PatientService::class);
                $patientId = $patientService->resolvePatient($data);
            }

            // قفل صف الفرع لمنع الـ Deadlocks وضمان تسلسل أرقام الدور
            DB::table('branches')->where('id', $branchId)->lockForUpdate()->first();

            [$startTime, $endTime] = ShiftHelper::getShiftWindow();
            $shiftDate = Carbon::parse($startTime)->toDateString();
            $doctorId = $data['doctor_id'] ?? null;

            $maxQueueQuery = LiveQueue::where('branch_id', $branchId)
                ->where(function ($q) use ($shiftDate, $startTime, $endTime) {
                    $q->where('shift_date', $shiftDate)
                        ->orWhereBetween('created_at', [$startTime, $endTime]);
                });

            if ($doctorId) {
                $maxQueueQuery->where('doctor_id', $doctorId);
            }

            $maxQueueNo = $maxQueueQuery->max('queue_no') ?? 0;

            $queueItem = LiveQueue::create([
                'branch_id' => $branchId,
                'doctor_id' => $doctorId,
                'patient_id' => $patientId,
                'appointment_id' => null, // 🛡️ Zero appointments
                'encounter_id' => null,
                'shift_date' => $shiftDate,
                'queue_no' => $maxQueueNo + 1,
                'checked_in_at' => now()->toTimeString(),
                'status' => LiveQueueStatus::CHECKED_IN->value,
            ]);

            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in checkInWalkIn: '.$e->getMessage());
                }
            });

            return $queueItem->load(['patient', 'doctor']);
        });
    }

    /**
     * إلغاء دور مريض في صالة الانتظار (Walk-Away)
     */
    public function cancelQueueItem(string $id): LiveQueue
    {
        return DB::transaction(function () use ($id) {
            $queueItem = LiveQueue::lockForUpdate()->findOrFail($id);

            $statusValue = $queueItem->status instanceof LiveQueueStatus ? $queueItem->status->value : (string) $queueItem->status;

            if (! in_array($statusValue, [LiveQueueStatus::CHECKED_IN->value, LiveQueueStatus::WAITING->value])) {
                throw new \InvalidArgumentException('لا يمكن إلغاء دور مريض قيد الفحص أو مكتمل.', 422);
            }

            $queueItem->update(['status' => LiveQueueStatus::CANCELLED->value]);

            if ($queueItem->appointment_id) {
                Appointment::where('id', $queueItem->appointment_id)
                    ->update(['status' => AppointmentStatus::CANCELLED->value]);
            }

            $branchId = $queueItem->branch_id;
            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in cancelQueueItem: '.$e->getMessage());
                }
            });

            return $queueItem->load('patient');
        });
    }

    /**
     * تحديث حالة مريض في الصالة وتحديث الحجز المرتبط به
     */
    public function updateStatus(string $id, string $status): LiveQueue
    {
        return DB::transaction(function () use ($id, $status) {
            $queueItem = LiveQueue::lockForUpdate()->findOrFail($id);
            $queueItem->update(['status' => $status]);

            // 🎯 المزامنة الذرية مع جدول الحجوزات الأساسي (SSOT)
            if ($queueItem->appointment_id) {
                // Check if transitioning to completed with an unpaid invoice
                if ($status === LiveQueueStatus::COMPLETED->value || $status === 'completed') {
                    $invoice = Invoice::where('appointment_id', $queueItem->appointment_id)->first();
                    $isPaid = $invoice && ($invoice->payment_status === PaymentStatus::PAID || $invoice->payment_status === PaymentStatus::PAID->value);
                    if ($invoice && ! $isPaid) {
                        // Route through pending_payment instead of immediate completion
                        Appointment::where('id', $queueItem->appointment_id)
                            ->update(['status' => AppointmentStatus::PENDING_PAYMENT->value]);
                        app(BillingService::class)->markInvoiceReadyForPayment($invoice);

                        $branchId = $queueItem->branch_id;
                        DB::afterCommit(function () use ($branchId) {
                            try {
                                event(new LiveQueueUpdated($branchId));
                            } catch (\Throwable $e) {
                                logger()->warning('WebSocket broadcast failed in updateStatus: '.$e->getMessage());
                            }
                        });

                        return $queueItem->load('patient');
                    }
                }

                $mappedStatus = match ($status) {
                    LiveQueueStatus::COMPLETED->value => AppointmentStatus::COMPLETED->value,
                    LiveQueueStatus::UNDER_EXAMINATION->value => AppointmentStatus::UNDER_EXAMINATION->value,
                    LiveQueueStatus::CHECKED_IN->value, LiveQueueStatus::WAITING->value => AppointmentStatus::CHECKED_IN->value,
                    default => null,
                };

                if ($mappedStatus) {
                    Appointment::where('id', $queueItem->appointment_id)
                        ->update(['status' => $mappedStatus]);
                }
            }

            $branchId = $queueItem->branch_id;
            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in updateStatus: '.$e->getMessage());
                }
            });

            return $queueItem->load('patient');
        });
    }

    /**
     * استدعاء المريض التالي للكشف
     */
    public function callNextPatient(string $branchId, ?int $doctorId = null, ?string $roomName = null): ?LiveQueue
    {
        return DB::transaction(function () use ($branchId, $doctorId, $roomName) {
            [$startTime, $endTime] = ShiftHelper::getShiftWindow();

            // 🛡️ Active Encounter Guard: لا يمكن استدعاء مريض جديد إذا كان لدى الطبيب كشف جاري لم ينتهِ
            if ($doctorId) {
                $activeEncounter = Encounter::where('doctor_id', $doctorId)
                    ->where('branch_id', $branchId)
                    ->where('status', EncounterStatus::IN_PROGRESS->value)
                    ->first();

                if ($activeEncounter) {
                    throw new \InvalidArgumentException(
                        "لديك كشف طبي جاري بالفعل (ID: {$activeEncounter->id}). يرجى إنهاء الكشف الحالي أو إلغاؤه قبل استدعاء مريض جديد.",
                        409
                    );
                }
            }

            // 1. جلب المريض التالي الخاص بهذا الطبيب (أو من الطابور العام إذا لم يكن محدداً)
            $nextPatientQuery = LiveQueue::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->whereIn('status', [LiveQueueStatus::CHECKED_IN->value, LiveQueueStatus::WAITING->value]);

            if ($doctorId) {
                $nextPatientQuery->where(function ($q) use ($doctorId) {
                    $q->where('doctor_id', $doctorId)->orWhereNull('doctor_id');
                });
            }

            $nextPatient = $nextPatientQuery->orderBy('queue_no', 'asc')
                ->lockForUpdate()
                ->first();

            if (! $nextPatient) {
                DB::afterCommit(function () use ($branchId) {
                    try {
                        event(new LiveQueueUpdated($branchId));
                    } catch (\Throwable $e) {
                        logger()->warning('WebSocket broadcast failed in callNextPatient: '.$e->getMessage());
                    }
                });

                return null;
            }

            // 2. تحويل الحالة إلى تحت الكشف وتعيين الطبيب
            $effectiveDoctorId = $doctorId ?? $nextPatient->doctor_id ?? auth()->id();

            $nextPatient->update([
                'status' => LiveQueueStatus::UNDER_EXAMINATION->value,
                'doctor_id' => $effectiveDoctorId,
            ]);

            if ($nextPatient->appointment_id) {
                Appointment::where('id', $nextPatient->appointment_id)->update([
                    'status' => AppointmentStatus::UNDER_EXAMINATION->value,
                    'doctor_id' => $effectiveDoctorId,
                ]);
            }

            // 3. إنشاء وربط سجل الـ Encounter
            $encounterService = app(EncounterService::class);
            $encounter = $encounterService->startEncounterFromQueue($nextPatient, (int) $effectiveDoctorId);

            $nextPatient->update(['encounter_id' => $encounter->id]);
            $nextPatient->setRelation('encounter', $encounter);
            $nextPatient->load(['patient', 'appointment', 'doctor']);

            // 4. إطلاق البث للشاشات بالبيانات الحقيقية للطبيب والغرفة
            DB::afterCommit(function () use ($branchId, $nextPatient, $roomName) {
                try {
                    $doctorName = $nextPatient->doctor?->name ?? auth()->user()?->name ?? 'طبيب العيادة';
                    $callPayload = [
                        'queue_no' => $nextPatient->queue_no,
                        'patient_name' => $nextPatient->patient->name ?? 'Unknown',
                        'doctor_name' => $doctorName,
                        'room_name' => $roomName ?? 'Examination Room',
                    ];
                    event(new NextPatientCalled($branchId, $callPayload));
                    event(new PublicNextPatientCalled($branchId, $callPayload));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket NextPatientCalled broadcast failed: '.$e->getMessage());
                }

                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket LiveQueueUpdated broadcast failed in callNextPatient: '.$e->getMessage());
                }
            });

            return $nextPatient;
        });
    }

    /**
     * إعادة ترتيب طابور الانتظار بـ High Performance وبدون تضارب مع المرتين المنتهية
     */
    public function reorderQueue(array $orderedIds, string $branchId): void
    {
        // لو مفيش عناصر مبعوثة، نخرج فوراً
        if (empty($orderedIds)) {
            return;
        }

        DB::transaction(function () use ($orderedIds, $branchId) {
            // 1. قفل صف الفرع لمنع الـ Deadlocks
            DB::table('branches')->where('id', $branchId)->lockForUpdate()->get();

            // 🛡️ التحقق من أن جميع العناصر المراد إعادة ترتيبها موجودة في قائمة الانتظار فقط
            $invalidCount = DB::table('live_queues')
                ->whereIn('id', $orderedIds)
                ->where('branch_id', $branchId)
                ->whereNotIn('status', [LiveQueueStatus::CHECKED_IN->value, LiveQueueStatus::WAITING->value])
                ->count();

            if ($invalidCount > 0) {
                throw new \InvalidArgumentException('يمكن إعادة ترتيب المرضى الموجودين في صالة الانتظار فقط.', 422);
            }

            // 2. جلب أرقام الدور الحالية الخاصة بالعناصر المراد ترتيبها وترتيبها تصاعدياً
            // لكي نحافظ على نفس نطاق الأرقام النشطة (مثلاً لو الباقي 2، 3، 4 نحتفظ بهم ولا نبدأ من 1)
            $currentQueueItems = DB::table('live_queues')
                ->whereIn('id', $orderedIds)
                ->where('branch_id', $branchId)
                ->orderBy('queue_no', 'asc')
                ->pluck('queue_no', 'id')
                ->toArray();

            // استخراج الأرقام وترتيبها تصاعدياً لتصبح هي الأرقام المتاحة للتوزيع الجديد
            $availableQueueNumbers = array_values($currentQueueItems);
            sort($availableQueueNumbers);

            // 3. تفريغ قيم queue_no للمرضى المحددين بإعطائهم أرقام سالبة مؤقتة
            // لتفريغ القيم الإيجابية وتجنب الـ Unique Constraint مع المريض الـ Completed (اللي رقمه 1 مثلاً)
            DB::table('live_queues')
                ->whereIn('id', $orderedIds)
                ->where('branch_id', $branchId)
                ->update(['queue_no' => DB::raw('-queue_no')]);

            // 4. بناء استعلام CASE WHEN لتوزيع أرقام الدور الصحيحة بناءً على الترتيب الجديد القادم من الفرونت إند
            $cases = [];
            $params = [];
            foreach ($orderedIds as $index => $id) {
                // نأخذ الرقم من مجموعة الأرقام المتاحة ونربطه بالـ ID الجديد في الترتيب
                $assignedQueueNo = $availableQueueNumbers[$index];

                $cases[] = 'WHEN id = ? THEN ?';
                $params[] = $id;
                $params[] = $assignedQueueNo;
            }

            $casesSql = implode(' ', $cases);

            // تنفيذ التحديث النهائي في Query واحد سريع جداً
            DB::statement(
                "UPDATE live_queues SET queue_no = CASE {$casesSql} END WHERE id IN (".implode(',', array_fill(0, count($orderedIds), '?')).') AND branch_id = ?',
                array_merge($params, $orderedIds, [$branchId])
            );

            // 5. إطلاق WebSockets بعد Commit التعديلات
            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new QueueReordered($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in reorderQueue: '.$e->getMessage());
                }
            });
        });
    }

    /**
     * حذف مريض من طابور الانتظار
     */
    public function destroyQueueItem(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $queueItem = LiveQueue::lockForUpdate()->findOrFail($id);
            $branchId = $queueItem->branch_id;

            // إلغاء الحجز الأصلي المرتبط بهذا المريض
            if ($queueItem->appointment_id) {
                Appointment::where('id', $queueItem->appointment_id)
                    ->update(['status' => AppointmentStatus::BOOKING->value]);
            }
            $deleted = (bool) $queueItem->delete();

            if ($deleted) {
                DB::afterCommit(function () use ($branchId) {
                    try {
                        event(new LiveQueueUpdated($branchId));
                    } catch (\Throwable $e) {
                        logger()->warning('WebSocket broadcast failed in destroyQueueItem: '.$e->getMessage());
                    }
                });
            }

            return $deleted;
        });
    }
}
