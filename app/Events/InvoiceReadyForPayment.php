<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a patient's consultation finishes or doctor calls next patient,
 * moving the appointment to pending_payment and alerting reception desk.
 */
class InvoiceReadyForPayment implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $branchId;
    public array $invoiceData;

    public function __construct(Invoice $invoice)
    {
        $this->branchId = (string) $invoice->branch_id;

        $invoice->loadMissing(['patient', 'appointment.doctor', 'items']);

        $this->invoiceData = [
            'invoice_id'     => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'appointment_id' => (string) $invoice->appointment_id,
            'patient_id'     => (string) $invoice->patient_id,
            'patient_name'   => $invoice->patient?->name ?? 'مريض',
            'doctor_name'    => $invoice->appointment?->doctor?->name ?? 'طبيب',
            'subtotal'       => (float) $invoice->subtotal,
            'discount'       => (float) $invoice->discount,
            'total'          => (float) $invoice->total,
            'branch_id'      => (string) $invoice->branch_id,
            'items'          => $invoice->items->map(fn ($item) => [
                'id'         => (string) $item->id,
                'name'       => $item->item_name,
                'unit_price' => (float) $item->unit_price,
                'quantity'   => (int) $item->quantity,
                'total'      => (float) $item->total,
            ])->toArray(),
            'created_at'     => now()->toISOString(),
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
        return 'invoice.ready_for_payment';
    }

    public function broadcastWith(): array
    {
        return $this->invoiceData;
    }
}
