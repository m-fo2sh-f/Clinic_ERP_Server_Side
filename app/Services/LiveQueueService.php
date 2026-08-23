<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\LiveQueueStatus;
use App\Models\Appointment;
use App\Models\LiveQueue;
use Carbon\Carbon;
use App\Helpers\ShiftHelper;
use Illuminate\Support\Facades\DB;
use App\Events\QueueReordered;
use App\Events\LiveQueueUpdated;
use App\Events\NextPatientCalled;

class LiveQueueService
{
    /**
     * جلب الطابور الحي للفرع بناءً على شفت اليوم الطبي الشغال حالياً
     */
    public function getQueueForBranch(string $branchId)
    {
        [$startTime, $endTime] = ShiftHelper::getShiftWindow();

        return LiveQueue::where('branch_id', $branchId)
            ->with(['patient', 'appointment'])
            ->whereBetween('created_at', [$startTime, $endTime])
            ->whereIn('status', LiveQueueStatus::activeStatuses())
            ->orderBy('queue_no', 'asc')    
            ->get();
    }

    /**
     * إدراج مريض داخل طابور الانتظار الحي (Check-In)
     */
    public function createNewPatientInQueue(array $data, string $branchId): LiveQueue
    {
        return DB::transaction(function () use ($data, $branchId) {
            [$startTime, $endTime] = ShiftHelper::getShiftWindow();
            $shiftDate = Carbon::parse($startTime)->toDateString();

            // Lock parent branch row to prevent MySQL InnoDB gap locks & deadlocks
            DB::table('branches')->where('id', $branchId)->lockForUpdate()->get();

            $maxQueueNo = LiveQueue::where('branch_id', $branchId)
                ->where(function ($q) use ($shiftDate, $startTime, $endTime) {
                    $q->where('shift_date', $shiftDate)
                      ->orWhereBetween('created_at', [$startTime, $endTime]);
                })
                ->max('queue_no') ?? 0;

            $queueItem = LiveQueue::create([
                'branch_id'      => $branchId,
                'shift_date'     => $shiftDate,
                'patient_id'     => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'queue_no'       => $maxQueueNo + 1,
                'checked_in_at'  => now()->toTimeString(),
                'status'         => LiveQueueStatus::CHECKED_IN->value,
            ]);

            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in createNewPatientInQueue: ' . $e->getMessage());
                }
            });

            return $queueItem;
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
                $mappedStatus = match ($status) {
                    LiveQueueStatus::COMPLETED->value         => AppointmentStatus::COMPLETED->value,
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
                    logger()->warning('WebSocket broadcast failed in updateStatus: ' . $e->getMessage());
                }
            });

            return $queueItem->load('patient');
        });
    }

    /**
     * استدعاء المريض التالي للكشف
     */
    public function callNextPatient(string $branchId): ?LiveQueue
    {
        return DB::transaction(function () use ($branchId) {
            [$startTime, $endTime] = ShiftHelper::getShiftWindow();

            // 1. إنهاء كشف المريض الحالي (إن وجد) وتحديث الحجز
            $currentExamining = LiveQueue::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->where('status', LiveQueueStatus::UNDER_EXAMINATION->value)
                ->lockForUpdate()
                ->first();

            if ($currentExamining) {
                $currentExamining->update(['status' => LiveQueueStatus::COMPLETED->value]);

                if ($currentExamining->appointment_id) {
                    Appointment::where('id', $currentExamining->appointment_id)
                        ->update(['status' => AppointmentStatus::COMPLETED->value]);
                }
            }

            // 2. جلب وتثبيت المريض التالي (Checked-In / Waiting)
            $nextPatient = LiveQueue::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->whereIn('status', [LiveQueueStatus::CHECKED_IN->value, LiveQueueStatus::WAITING->value])
                ->orderBy('queue_no', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$nextPatient) {
                DB::afterCommit(function () use ($branchId) {
                    try {
                        event(new LiveQueueUpdated($branchId));
                    } catch (\Throwable $e) {
                        logger()->warning('WebSocket broadcast failed in callNextPatient: ' . $e->getMessage());
                    }
                });
                return null;
            }

            // 3. تحويل حالة المريض الجديد إلى كشف في الجدولين
            $nextPatient->update(['status' => LiveQueueStatus::UNDER_EXAMINATION->value]);

            if ($nextPatient->appointment_id) {
                Appointment::where('id', $nextPatient->appointment_id)
                    ->update(['status' => AppointmentStatus::UNDER_EXAMINATION->value]);
            }

            $nextPatient->load(['patient', 'appointment']);

            // 4. إطلاق الأحداث فور نجاح الترانزاكشن
            DB::afterCommit(function () use ($branchId, $nextPatient) {
                try {
                    event(new NextPatientCalled($branchId, [
                        'queue_no'     => $nextPatient->queue_no,
                        'patient_name' => $nextPatient->patient->name ?? 'Unknown',
                        'doctor_name'  => 'Dr. Ahmed',
                        'room_name'    => 'Room 1',
                    ]));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket NextPatientCalled broadcast failed: ' . $e->getMessage());
                }

                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket LiveQueueUpdated broadcast failed in callNextPatient: ' . $e->getMessage());
                }
            });

            return $nextPatient;
        });
    }

    /**
     * إعادة ترتيب طابور الانتظار
     */
    /**
 * إعادة ترتيب طابور الانتظار بـ High Performance
 */
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

                $cases[] = "WHEN id = ? THEN ?";
                $params[] = $id;
                $params[] = $assignedQueueNo; 
            }

            $casesSql = implode(' ', $cases);

            // تنفيذ التحديث النهائي في Query واحد سريع جداً
            DB::statement(
                "UPDATE live_queues SET queue_no = CASE {$casesSql} END WHERE id IN (" . implode(',', array_fill(0, count($orderedIds), '?')) . ") AND branch_id = ?",
                array_merge($params, $orderedIds, [$branchId] )
            );

            // 5. إطلاق WebSockets بعد Commit التعديلات
            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new QueueReordered($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in reorderQueue: ' . $e->getMessage());
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
            $branchId  = $queueItem->branch_id;

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
                        logger()->warning('WebSocket broadcast failed in destroyQueueItem: ' . $e->getMessage());
                    }
                });
            }

            return $deleted;
        });
    }
}