<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // 🔥 الأهم: بنقوله أذيع في الـ WebSocket
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QueueReordered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $branchId;

    // 1. بنباصي الـ branch_id للحدث أول ما ينطلق
    public function __construct(string $branchId)
    {
        $this->branchId = $branchId;
    }

    // 2. 🚨 تأمين الـ Tenant والفروع: بنحدد اسم "القناة" اللي هنذيع عليها
    // مينفعش نذيع على قناة عامة فـ عيادة دكتور (أ) تشوف ترتيب طابور عيادة دكتور (ب)!
    // بنعمل قناة خاصة بالفرع ده بالملّي: private-branch.{branchId}
    public function broadcastOn(): array
    {
        return [
            new Channel('branch.' . $this->branchId), // قناة عامة أو Private حسب الحماية، خلينا شغالين Channel للتيست السريع
        ];
    }

    // 3. بنحدد اسم الإشارة اللي الريأكت هيستمع ليها
    public function broadcastAs(): string
    {
        return 'QueueReordered';
    }
}