<?php

namespace App\Services\Clinic;

use App\Enums\AppointmentStatus;
use App\Enums\EncounterStatus;
use App\Enums\LiveQueueStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\InvoicePaid;
use App\Events\InvoiceReadyForPayment;
use App\Models\Appointment;
use App\Models\BranchService;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LiveQueue;
use App\Models\Payment;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    /**
     * Create an initial unpaid invoice for a newly checked-in appointment.
     * Snapshots the base consultation fee at creation time.
     */
    public function createInvoiceForAppointment(Appointment $appointment): Invoice
    {
        return DB::transaction(function () use ($appointment) {
            $existing = Invoice::where('appointment_id', $appointment->id)->first();
            if ($existing) {
                return $existing->load(['items', 'patient', 'appointment']);
            }

            // Find consultation service
            $consultationService = Service::where('code', 'CONSULTATION')->first()
                ?? Service::where('code', 'GEN-01')->first()
                ?? Service::first();

            $unitPrice = 150.00;
            if ($consultationService) {
                $branchOverride = BranchService::where('branch_id', $appointment->branch_id)
                    ->where('service_id', $consultationService->id)
                    ->where('is_available', true)
                    ->value('price');

                $unitPrice = $branchOverride !== null ? (float) $branchOverride : (float) $consultationService->default_price;
            }

            $invoiceNumber = $this->generateInvoiceNumber();

            $invoice = Invoice::create([
                'invoice_number' => $invoiceNumber,
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'branch_id' => $appointment->branch_id,
                'subtotal' => $unitPrice,
                'discount' => 0.00,
                'total' => $unitPrice,
                'payment_status' => PaymentStatus::UNPAID->value,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $consultationService?->id,
                'item_name' => $consultationService?->name ?? 'كشف استشاري',
                'unit_price' => $unitPrice,
                'quantity' => 1,
                'total' => $unitPrice,
            ]);

            return $invoice->load(['items', 'patient', 'appointment']);
        });
    }

    /**
     * Create an initial invoice for an encounter (Solo Walk-in or Polyclinic),
     * snapshotting service price(s) and consultation fees at creation time.
     */
    public function createInvoiceForEncounter(Encounter $encounter, array $serviceItems = [], float $discount = 0.0): Invoice
    {
        return DB::transaction(function () use ($encounter, $serviceItems, $discount) {
            $existing = Invoice::where('encounter_id', $encounter->id)->first();
            if ($existing) {
                return $existing->load(['items', 'patient', 'encounter']);
            }

            $invoiceNumber = $this->generateInvoiceNumber();

            $invoice = Invoice::create([
                'invoice_number' => $invoiceNumber,
                'encounter_id' => $encounter->id,
                'appointment_id' => $encounter->appointment_id,
                'patient_id' => $encounter->patient_id,
                'branch_id' => $encounter->branch_id,
                'subtotal' => 0.00,
                'discount' => max(0.0, $discount),
                'total' => 0.00,
                'payment_status' => PaymentStatus::UNPAID->value,
            ]);

            $totalSubtotal = 0.0;

            if (! empty($serviceItems)) {
                foreach ($serviceItems as $itemData) {
                    $serviceId = is_array($itemData) ? ($itemData['service_id'] ?? $itemData['id'] ?? null) : $itemData;
                    $quantity = is_array($itemData) ? max(1, (int) ($itemData['quantity'] ?? 1)) : 1;
                    if (! $serviceId) {
                        continue;
                    }

                    $service = Service::find($serviceId);
                    if (! $service) {
                        continue;
                    }

                    $branchOverride = BranchService::where('branch_id', $encounter->branch_id)
                        ->where('service_id', $service->id)
                        ->where('is_available', true)
                        ->value('price');

                    $unitPrice = $branchOverride !== null ? (float) $branchOverride : (float) $service->default_price;
                    $itemTotal = round($unitPrice * $quantity, 2);

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'service_id' => $service->id,
                        'item_name' => $service->name, // Snapshot
                        'unit_price' => $unitPrice,    // Snapshot
                        'quantity' => $quantity,
                        'total' => $itemTotal,
                    ]);

                    $totalSubtotal += $itemTotal;
                }
            } else {
                // Default consultation service snapshot
                $consultationService = Service::whereIn('code', ['CONSULTATION', 'GEN-01'])->first()
                    ?? Service::first();

                $unitPrice = 150.00;
                if ($consultationService) {
                    $branchOverride = BranchService::where('branch_id', $encounter->branch_id)
                        ->where('service_id', $consultationService->id)
                        ->where('is_available', true)
                        ->value('price');

                    $unitPrice = $branchOverride !== null ? (float) $branchOverride : (float) $consultationService->default_price;
                }

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_id' => $consultationService?->id,
                    'item_name' => $consultationService?->name ?? 'كشف استشاري',
                    'unit_price' => $unitPrice,
                    'quantity' => 1,
                    'total' => $unitPrice,
                ]);

                $totalSubtotal = $unitPrice;
            }

            $totalFinal = max(0.0, round($totalSubtotal - max(0.0, $discount), 2));
            $isZeroCost = ($totalFinal <= 0.0);

            $invoice->update([
                'subtotal' => $totalSubtotal,
                'total' => $totalFinal,
                'payment_status' => $isZeroCost ? PaymentStatus::PAID->value : PaymentStatus::UNPAID->value,
                'paid_at' => $isZeroCost ? now() : null,
            ]);

            return $invoice->load(['items', 'patient', 'encounter']);
        });
    }

    /**
     * Add an extra billable service to an existing invoice (e.g. ECG, X-Ray).
     * Snapshots service name and price at addition time.
     */
    public function addExtraService(string $invoiceId, string $serviceId, int $quantity = 1): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $serviceId, $quantity) {
            $invoice = Invoice::lockForUpdate()->with('appointment')->findOrFail($invoiceId);

            $currentStatus = $invoice->payment_status instanceof PaymentStatus
                ? $invoice->payment_status->value
                : $invoice->payment_status;

            if ($currentStatus === PaymentStatus::PAID->value) {
                throw new \InvalidArgumentException('لا يمكن إضافة خدمات لفاتورة مدفوعة بالكامل.');
            }

            $service = Service::findOrFail($serviceId);

            $branchOverride = BranchService::where('branch_id', $invoice->branch_id)
                ->where('service_id', $service->id)
                ->where('is_available', true)
                ->value('price');

            $unitPrice = $branchOverride !== null ? (float) $branchOverride : (float) $service->default_price;
            $quantity = max(1, $quantity);
            $itemTotal = round($unitPrice * $quantity, 2);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'item_name' => $service->name, // Snapshot
                'unit_price' => $unitPrice,    // Snapshot
                'quantity' => $quantity,
                'total' => $itemTotal,
            ]);

            $invoice->recalculateTotals();

            // If appointment is pending_payment, broadcast updated invoice
            if ($invoice->appointment && in_array($invoice->appointment->status?->value ?? $invoice->appointment->status, [
                AppointmentStatus::PENDING_PAYMENT->value,
                'pending_payment',
            ])) {
                DB::afterCommit(function () use ($invoice) {
                    try {
                        event(new InvoiceReadyForPayment($invoice));
                    } catch (\Throwable $e) {
                        logger()->warning('WebSocket broadcast failed in addExtraService: '.$e->getMessage());
                    }
                });
            }

            return $invoice->fresh(['items', 'patient', 'payments', 'appointment']);
        });
    }

    /**
     * Remove an item from an unpaid invoice.
     */
    public function removeServiceItem(string $invoiceId, string $itemId): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $itemId) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoiceId);

            $currentStatus = $invoice->payment_status instanceof PaymentStatus
                ? $invoice->payment_status->value
                : $invoice->payment_status;

            if ($currentStatus === PaymentStatus::PAID->value) {
                throw new \InvalidArgumentException('لا يمكن تعديل بنود فاتورة مدفوعة.');
            }

            $item = InvoiceItem::where('invoice_id', $invoice->id)->findOrFail($itemId);
            $item->delete();

            $invoice->recalculateTotals();

            return $invoice->fresh(['items', 'patient', 'payments', 'appointment']);
        });
    }

    /**
     * Process payment (Cash, Visa, or Split) atomically inside a DB transaction with lockForUpdate.
     * Completes invoice, completes appointment, and broadcasts InvoicePaid.
     *
     * @param  array  $paymentsData  [['method' => 'cash', 'amount' => 100], ['method' => 'visa', 'amount' => 50, 'transaction_reference' => '...']]
     */
    public function processPayment(string $invoiceId, array $paymentsData, ?int $cashierId = null): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $paymentsData, $cashierId) {
            $invoice = Invoice::lockForUpdate()->with(['appointment', 'patient'])->findOrFail($invoiceId);

            $currentStatus = $invoice->payment_status instanceof PaymentStatus
                ? $invoice->payment_status->value
                : $invoice->payment_status;

            if ($currentStatus === PaymentStatus::PAID->value) {
                throw new \InvalidArgumentException('الفاتورة مدفوعة بالكامل بالفعل.');
            }

            if (empty($paymentsData)) {
                throw new \InvalidArgumentException('يجب إدخال تفاصيل الدفع.');
            }

            $totalPaid = 0.0;
            foreach ($paymentsData as $entry) {
                $amount = (float) ($entry['amount'] ?? 0);
                if ($amount <= 0) {
                    throw new \InvalidArgumentException('يجب أن يكون مبلغ الدفعة أكبر من صفر.');
                }
                $method = $entry['method'] ?? null;
                if (! in_array($method, PaymentMethod::values())) {
                    throw new \InvalidArgumentException('طريقة الدفع غير صالحة: '.$method);
                }
                $totalPaid += $amount;
            }

            $invoiceTotal = (float) $invoice->total;
            if (abs($totalPaid - $invoiceTotal) > 0.01) {
                throw new \InvalidArgumentException("المبلغ المدفوع ({$totalPaid}) يجب أن يطابق إجمالي الفاتورة المطلوب ({$invoiceTotal}).");
            }

            // Create individual payment records
            foreach ($paymentsData as $entry) {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'cashier_id' => $cashierId ?? auth()->id(),
                    'amount' => (float) $entry['amount'],
                    'payment_method' => $entry['method'],
                    'transaction_reference' => $entry['transaction_reference'] ?? null,
                    'paid_at' => now(),
                ]);
            }

            // Update invoice status
            $invoice->update([
                'payment_status' => PaymentStatus::PAID->value,
                'paid_at' => now(),
            ]);

            // Transition live queue status to completed (via encounter_id or appointment_id)
            LiveQueue::where(function ($q) use ($invoice) {
                if ($invoice->encounter_id) {
                    $q->where('encounter_id', $invoice->encounter_id);
                }
                if ($invoice->appointment_id) {
                    $q->orWhere('appointment_id', $invoice->appointment_id);
                }
            })->update([
                'status' => LiveQueueStatus::COMPLETED->value,
            ]);

            // Transition appointment status if present
            $appointmentId = $invoice->appointment_id ?? ($invoice->encounter?->appointment_id);
            if ($appointmentId) {
                Appointment::where('id', $appointmentId)->update([
                    'status' => AppointmentStatus::COMPLETED->value,
                ]);
            }

            // Transition encounter status to completed if not already completed
            if ($invoice->encounter_id) {
                $enc = Encounter::where('id', $invoice->encounter_id)->first();
                if ($enc && $enc->status !== EncounterStatus::COMPLETED) {
                    $enc->update([
                        'status' => EncounterStatus::COMPLETED->value,
                        'completed_at' => now(),
                    ]);
                }
            }

            // Broadcast InvoicePaid & LiveQueueUpdated events
            $branchId = $invoice->branch_id;
            DB::afterCommit(function () use ($invoice, $branchId) {
                try {
                    event(new InvoicePaid($invoice));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed in processPayment: '.$e->getMessage());
                }
                try {
                    event(new LiveQueueUpdated($branchId));
                } catch (\Throwable $e) {
                    logger()->warning('WebSocket broadcast failed for queue in processPayment: '.$e->getMessage());
                }
            });

            return $invoice->fresh(['items', 'payments.cashier', 'patient', 'appointment.doctor']);
        });
    }

    /**
     * Mark appointment ready for payment and broadcast event to reception desk.
     */
    public function markInvoiceReadyForPayment(Invoice $invoice): void
    {
        if ($invoice->appointment_id) {
            Appointment::where('id', $invoice->appointment_id)->update([
                'status' => AppointmentStatus::PENDING_PAYMENT->value,
            ]);
        }

        DB::afterCommit(function () use ($invoice) {
            try {
                event(new InvoiceReadyForPayment($invoice));
            } catch (\Throwable $e) {
                logger()->warning('WebSocket broadcast failed in markInvoiceReadyForPayment: '.$e->getMessage());
            }
        });
    }

    /**
     * Get pending invoices for a specific branch (for reception live payment drawer).
     * Returns unpaid invoices where the appointment or encounter has completed examination,
     * preventing premature display while patients are still waiting or under examination.
     */
    public function getPendingInvoices(string $branchId): Collection
    {
        return Invoice::where('branch_id', $branchId)
            ->where('payment_status', '!=', PaymentStatus::PAID->value)
            ->where(function ($query) {
                // If linked to an appointment, only show if reached pending_payment or completed
                $query->whereHas('appointment', function ($q) {
                    $q->whereIn('status', [
                        AppointmentStatus::PENDING_PAYMENT->value,
                        'pending_payment',
                        AppointmentStatus::COMPLETED->value,
                        'completed',
                    ]);
                })
                // OR if linked to an encounter (e.g. walk-in), show if encounter is completed
                ->orWhereHas('encounter', function ($q) {
                    $q->whereIn('status', [
                        EncounterStatus::COMPLETED->value,
                        'completed',
                    ]);
                })
                // OR if invoice has neither (standalone invoice created for patient)
                ->orWhere(function ($q) {
                    $q->whereNull('appointment_id')->whereNull('encounter_id');
                });
            })
            ->with(['patient', 'appointment.doctor', 'encounter.doctor', 'items'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get or create an invoice for a specific queue item (walk-in or appointment-backed).
     */
    public function getOrCreateInvoiceForQueueItem(LiveQueue $queueItem): Invoice
    {
        if ($queueItem->encounter_id) {
            $encounter = Encounter::find($queueItem->encounter_id);
            if ($encounter) {
                return $this->createInvoiceForEncounter($encounter);
            }
        }

        if ($queueItem->appointment_id) {
            $appointment = Appointment::find($queueItem->appointment_id);
            if ($appointment) {
                return $this->createInvoiceForAppointment($appointment);
            }
        }

        // For walk-in without encounter yet, find existing unpaid invoice or create one
        $existing = Invoice::where('patient_id', $queueItem->patient_id)
            ->where('branch_id', $queueItem->branch_id)
            ->where('payment_status', '!=', PaymentStatus::PAID->value)
            ->latest()
            ->first();

        if ($existing) {
            return $existing->load(['items', 'patient', 'appointment.doctor', 'encounter.doctor']);
        }

        $invoiceNumber = $this->generateInvoiceNumber();
        $invoice = Invoice::create([
            'invoice_number' => $invoiceNumber,
            'patient_id'     => $queueItem->patient_id,
            'branch_id'      => $queueItem->branch_id,
            'subtotal'       => 0.00,
            'discount'       => 0.00,
            'total'          => 0.00,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);

        // Default consultation service snapshot
        $consultationService = Service::whereIn('code', ['CONSULTATION', 'GEN-01'])->first()
            ?? Service::first();

        $unitPrice = 150.00;
        if ($consultationService) {
            $branchOverride = BranchService::where('branch_id', $queueItem->branch_id)
                ->where('service_id', $consultationService->id)
                ->where('is_available', true)
                ->value('price');

            $unitPrice = $branchOverride !== null ? (float) $branchOverride : (float) $consultationService->default_price;
        }

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $consultationService?->id,
            'item_name'  => $consultationService?->name ?? 'كشف استشاري',
            'unit_price' => $unitPrice,
            'quantity'   => 1,
            'total'      => $unitPrice,
        ]);

        return $invoice->recalculateTotals()->load(['items', 'patient', 'appointment.doctor', 'encounter.doctor']);
    }

    /**
     * Get paginated invoices with filters.
     */
    public function getInvoicesPaginated(string $branchId, array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::where('branch_id', $branchId)
            ->with(['patient', 'appointment.doctor', 'items', 'payments.cashier']);

        if (! empty($filters['status'])) {
            $query->where('payment_status', $filters['status']);
        }

        if (! empty($filters['date'])) {
            $startDate = Carbon::parse($filters['date'])->startOfDay();
            $endDate = Carbon::parse($filters['date'])->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($pq) => $pq->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get all services available for a branch with effective pricing.
     */
    public function getBranchServices(string $branchId): \Illuminate\Support\Collection
    {
        $services = Service::where('is_active', true)->get();
        $overrides = BranchService::where('branch_id', $branchId)
            ->where('is_available', true)
            ->get()
            ->keyBy('service_id');

        return $services->map(function ($svc) use ($overrides) {
            $override = $overrides->get($svc->id);

            return [
                'id' => (string) $svc->id,
                'name' => $svc->name,
                'code' => $svc->code,
                'price' => $override ? (float) $override->price : (float) $svc->default_price,
                'is_available' => $override ? (bool) $override->is_available : true,
            ];
        });
    }

    /**
     * Create a new service and set its price for branch(es).
     */
    public function createService(array $data, ?string $branchId = null): Service
    {
        return DB::transaction(function () use ($data, $branchId) {
            $price = (float) ($data['price'] ?? $data['default_price'] ?? 0);
            $service = Service::create([
                'name' => $data['name'],
                'code' => $data['code'] ?? strtoupper(Str::slug($data['name'], '_')),
                'default_price' => $price,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($branchId) {
                BranchService::updateOrCreate(
                    [
                        'branch_id' => $branchId,
                        'service_id' => $service->id,
                    ],
                    [
                        'price' => $price,
                        'is_available' => true,
                    ]
                );
            }

            return $service;
        });
    }

    /**
     * Update an existing service name, code, default price and branch price.
     */
    public function updateService(string $serviceId, array $data, ?string $branchId = null): Service
    {
        return DB::transaction(function () use ($serviceId, $data, $branchId) {
            $service = Service::findOrFail($serviceId);

            $updates = [];
            if (isset($data['name'])) {
                $updates['name'] = $data['name'];
            }
            if (isset($data['code'])) {
                $updates['code'] = $data['code'];
            }
            if (isset($data['price']) || isset($data['default_price'])) {
                $updates['default_price'] = (float) ($data['price'] ?? $data['default_price']);
            }
            if (isset($data['is_active'])) {
                $updates['is_active'] = (bool) $data['is_active'];
            }

            if (! empty($updates)) {
                $service->update($updates);
            }

            if ($branchId && (isset($data['price']) || isset($data['default_price']))) {
                $price = (float) ($data['price'] ?? $data['default_price']);
                BranchService::updateOrCreate(
                    [
                        'branch_id' => $branchId,
                        'service_id' => $service->id,
                    ],
                    [
                        'price' => $price,
                        'is_available' => true,
                    ]
                );
            }

            return $service;
        });
    }

    /**
     * Deactivate or delete a service.
     */
    public function deleteService(string $serviceId): bool
    {
        $service = Service::findOrFail($serviceId);

        return (bool) $service->update(['is_active' => false]);
    }

    /**
     * Generate sequential/unique invoice number: INV-YYYYMMDD-XXXXXX
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        $random = strtoupper(Str::random(5));

        return $prefix.$random;
    }
}
