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

                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in callNextPatient: ' . $e->getMessage());
                }
            });

            return $nextPatient;
        });
    }

    /**
     * إعادة ترتيب طابور الانتظار
     */
    public function reorderQueue(array $orderedIds, string $branchId): void
    {
        DB::transaction(function () use ($orderedIds, $branchId) {
            foreach ($orderedIds as $index => $id) {
                DB::table('live_queues')
                    ->where('id', $id)
                    ->where('branch_id', $branchId)
                    ->update(['queue_no' => $index + 1]);
            }

            DB::afterCommit(function () use ($branchId) {
                try {
                    event(new QueueReordered($branchId));
                    event(new LiveQueueUpdated($branchId));
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