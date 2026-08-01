<?php

namespace App\Services;

use App\Enums\LiveQueueStatus;
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
    public function getQueueForBranch($branchId)
    {
        [$startTime, $endTime] = ShiftHelper::getShiftWindow();

        return LiveQueue::where('branch_id', $branchId)
            ->with('patient')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->whereIn('status', LiveQueueStatus::activeStatuses())
            ->orderBy('queue_no', 'asc')    
            ->get();
    }

    /**
     * إدراج مريض داخل طابور الانتظار الحي (Check-In)
     */
    public function createNewPatientInQueue(array $data, $branchId)
    {
        $queueItem = DB::transaction(function () use ($data, $branchId) {
            [$startTime, $endTime] = ShiftHelper::getShiftWindow();

            $maxQueueNo = LiveQueue::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->lockForUpdate() 
                ->max('queue_no') ?? 0;

            return LiveQueue::create([
                'branch_id'      => $branchId,
                'patient_id'     => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'queue_no'       => $maxQueueNo + 1,
                'checked_in_at'  => now()->toTimeString(),
                'status'         => LiveQueueStatus::WAITING->value,
            ]);
        });

        // 🎯 السحر هنا: إطلاق الـ WebSocket فور إكتمال الـ Check-In
        try {
            event(new LiveQueueUpdated($branchId));
        } catch (\Throwable $e) {
            logger()->warning('WebSocket broadcast failed in createNewPatientInQueue: ' . $e->getMessage());
        }

        return $queueItem;
    }

    /**
     * تحديث حالة مريض في الصالة
     */
    public function updateStatus(string $id, string $status): LiveQueue
    {
        $queueItem = LiveQueue::findOrFail($id);
        
        $queueItem->update(['status' => $status]);

        try {
            event(new LiveQueueUpdated($queueItem->branch_id));
        } catch (\Throwable $e) {
            logger()->warning('WebSocket broadcast failed in updateStatus: ' . $e->getMessage());
        }

        return $queueItem->load('patient');
    }

    /**
     * استدعاء المريض التالي للكشف
     */
    public function callNextPatient(string $branchId): ?LiveQueue
    {
        return DB::transaction(function () use ($branchId) {
            [$startTime, $endTime] = ShiftHelper::getShiftWindow();

            // 1. إنهاء كشف المريض الحالي (إن وجد)
            LiveQueue::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->where('status', LiveQueueStatus::UNDER_EXAMINATION->value)
                ->update(['status' => LiveQueueStatus::COMPLETED->value]);

            // 2. جلب المريض التالي
            $nextPatient = LiveQueue::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->where('status', LiveQueueStatus::WAITING->value)
                ->orderBy('queue_no', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$nextPatient) {
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in callNextPatient: ' . $e->getMessage());
                }
                return null;
            }

            // 3. تحويل حالة المريض الجديد إلى كشف
            $nextPatient->update(['status' => LiveQueueStatus::UNDER_EXAMINATION->value]);

            $nextPatient->load(['patient', 'appointment']);

            // 4. إطلاق الأحداث فوراً
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
        });

        try {
            event(new QueueReordered($branchId));
            event(new LiveQueueUpdated($branchId)); // 🎯 إطلاق التحديث الشامل
        } catch (\Throwable $e) {
            logger()->warning('WebSocket broadcast failed in reorderQueue: ' . $e->getMessage());
        }
    }

    /**
     * حذف مريض من طابور الانتظار
     */
    public function destroyQueueItem(string $id): bool
    {
        $queueItem = LiveQueue::findOrFail($id);
        $branchId  = $queueItem->branch_id;
        
        $deleted = (bool) $queueItem->delete();

        if ($deleted) {
            // 🎯 إطلاق حدث التحديث عند حذف مريض
            try {
                event(new LiveQueueUpdated($branchId));
            } catch (\Throwable $e) {
                logger()->warning('WebSocket broadcast failed in destroyQueueItem: ' . $e->getMessage());
            }
        }

        return $deleted;
    }
}