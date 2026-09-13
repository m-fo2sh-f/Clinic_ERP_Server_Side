<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when reception processes payment for an invoice.
 */
class InvoicePaid implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $branchId;
    public array $paymentData;

    public function __construct(Invoice $invoice)
    {
        $this->branchId = (string) $invoice->branch_id;

        $invoice->loadMissing(['patient']);

        $this->paymentData = [
            'invoice_id'     => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'appointment_id' => (string) $invoice->appointment_id,
            'patient_id'     => (string) $invoice->patient_id,
            'patient_name'   => $invoice->patient?->name ?? 'مريض',
            'total'          => (float) $invoice->total,
            'payment_status' => $invoice->payment_status->value ?? 'paid',
            'paid_at'        => $invoice->paid_at?->toISOString() ?? now()->toISOString(),
            'branch_id'      => (string) $invoice->branch_id,
        ];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.' . $this->branchId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'invoice.paid';
    }

    public function broadcastWith(): array
    {
        return $this->paymentData;
    }
}
