<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the doctor calls the next patient.
 * Broadcasts to the unauthenticated public waiting room channel
 * with obfuscated patient name to safeguard medical privacy.
 */
class PublicNextPatientCalled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $branchId;

    public array $patientData;

    /**
     * @param  array  $patientData  ['queue_no', 'patient_name', 'doctor_name', 'room_name']
     */
    public function __construct(string $branchId, array $patientData)
    {
        $this->branchId = $branchId;

        // Anonymize/obfuscate patient name for public waiting room screens
        $fullName = trim((string) ($patientData['patient_name'] ?? 'مريض'));
        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $anonymizedName = count($parts) > 1
            ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.'
            : ($parts[0] ?? 'مريض');

        $this->patientData = [
            'queue_no' => $patientData['queue_no'] ?? null,
            'patient_name' => $anonymizedName,
            'doctor_name' => $patientData['doctor_name'] ?? 'طبيب العيادة',
            'room_name' => $patientData['room_name'] ?? 'غرفة الكشف',
        ];
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('public-queue.'.$this->branchId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'patient.called';
    }

    public function broadcastWith(): array
    {
        return $this->patientData;
    }
}
