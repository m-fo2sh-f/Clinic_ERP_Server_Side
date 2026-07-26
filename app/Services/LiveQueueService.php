<?php

namespace App\Services;

use App\Enums\LiveQueueStatus;
use App\Models\LiveQueue;
use Carbon\Carbon;
use App\Helpers\ShiftHelper;
use Illuminate\Support\Facades\DB;

class LiveQueueService
{
    /**
     * جلب الطابور الحي للفرع بناءً على شفت اليوم الطبي الشغال حالياً
     */
    public function getQueueForBranch($branchId)
    {
        [$startTime, $endTime] = ShiftHelper::getShiftWindow(); // Fetch shift boundaries from helper

        return LiveQueue::where('branch_id', $branchId)
            ->with('patient')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->whereIn('status', LiveQueueStatus::activeStatuses()) // Active queue statuses only
            ->orderBy('queue_no', 'asc')    
            ->get();
    }

    /**
     * إدراج مريض داخل طابور الانتظار الحي (Check-In)
     */
    
   public function createNewPatientInQueue(array $data, $branchId)
    {
        // 🎯 استخدام DB::transaction للحفاظ على سلامة المعاملة
        return DB::transaction(function () use ($data, $branchId) {
            [$startTime, $endTime] = ShiftHelper::getShiftWindow();

            // 🔒 lockForUpdate() بتجبر الريكويستات المتوازية تنتظر لحين استخراج أعلى رقم وتسجيله
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
    }

    public function updateStatus(string $id, string $status): LiveQueue
{
    $queueItem = LiveQueue::findOrFail($id);
    
    // Update queue status in database
    $queueItem->update(['status' => $status]);
    // 🚀 Optional: Trigger WebSocket event here to notify reception/TV screens live
    // event(new \App\Events\QueueUpdated($queueItem->branch_id));

    return $queueItem->load('patient'); // Return item with patient relation loaded
}
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
        // 🚀 طلقة الـ WebSocket: بنرمي الحدث في الجو وبنباصي الـ branchId
        event(new \App\Events\QueueReordered($branchId));
    }

    public function destroyQueueItem(string $id): bool
    {
        $queueItem = LiveQueue::findOrFail($id);
        
        return (bool) $queueItem->delete();
    }
}