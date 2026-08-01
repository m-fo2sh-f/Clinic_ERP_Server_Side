<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the doctor calls the next patient.
 * Carries explicit payload for TV screen announcement (chime + visual).
 */
class NextPatientCalled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $branchId;
    public array $patientData;

    /**
     * @param string $branchId
     * @param array  $patientData  ['queue_no', 'patient_name', 'doctor_name', 'room_name']
     */
    public function __construct(string $branchId, array $patientData)
    {
        $this->branchId = $branchId;
        $this->patientData = $patientData;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('branch.' . $this->branchId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'patient.called';
    }

    /**
     * Data payload sent to the WebSocket client.
     */
    public function broadcastWith(): array
    {
        return $this->patientData;
    }
}
