<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Billing\AddServiceItemRequest;
use App\Http\Requests\Api\V1\Billing\ProcessPaymentRequest;
use App\Models\Invoice;
use App\Services\Clinic\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    private BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Get paginated invoices for a branch.
     * GET /api/v1/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');
        if (!$branchId) {
            return response()->json(['status' => 'error', 'message' => 'branch_id is required'], 422);
        }

        $this->authorizeBranchAccess($request->user(), $branchId);

        $filters = [
            'status'   => $request->query('status'),
            'date'     => $request->query('date'),
            'search'   => $request->query('search'),
            'per_page' => $request->query('per_page', 15),
        ];

        $invoices = $this->billingService->getInvoicesPaginated($branchId, $filters);

        return response()->json([
            'status' => 'success',
            'data'   => $invoices,
        ]);
    }

    /**
     * Get pending invoices for the reception live drawer.
     * GET /api/v1/invoices/pending
     */
    public function pending(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');
        if (!$branchId) {
            return response()->json(['status' => 'error', 'message' => 'branch_id is required'], 422);
        }

        $this->authorizeBranchAccess($request->user(), $branchId);

        $invoices = $this->billingService->getPendingInvoices($branchId);

        return response()->json([
            'status' => 'success',
            'data'   => $invoices,
        ]);
    }

    /**
     * Get catalog of services with prices for a branch.
     * GET /api/v1/billing/services
     */
    public function services(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');
        if (!$branchId) {
            return response()->json(['status' => 'error', 'message' => 'branch_id is required'], 422);
        }

        $this->authorizeBranchAccess($request->user(), $branchId);

        $services = $this->billingService->getBranchServices($branchId);

        return response()->json([
            'status' => 'success',
            'data'   => $services,
        ]);
    }

    /**
     * Get single invoice details.
     * GET /api/v1/invoices/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['items.service', 'payments.cashier', 'patient', 'appointment.doctor', 'branch'])->findOrFail($id);

        $this->authorizeBranchAccess($request->user(), $invoice->branch_id);

        return response()->json([
            'status' => 'success',
            'data'   => $invoice,
        ]);
    }

    /**
     * Add extra service to an unpaid invoice.
     * POST /api/v1/invoices/{id}/items
     */
    public function addItem(AddServiceItemRequest $request, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $invoice->branch_id);

        $quantity = (int) ($request->validated('quantity') ?? 1);
        $updatedInvoice = $this->billingService->addExtraService($id, $request->validated('service_id'), $quantity);

        return response()->json([
            'status'  => 'success',
            'message' => 'تمت إضافة الخدمة بنجاح',
            'data'    => $updatedInvoice,
        ]);
    }

    /**
     * Remove an item from an unpaid invoice.
     * DELETE /api/v1/invoices/{id}/items/{itemId}
     */
    public function removeItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $invoice->branch_id);

        $updatedInvoice = $this->billingService->removeServiceItem($id, $itemId);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم حذف البند بنجاح',
            'data'    => $updatedInvoice,
        ]);
    }

    /**
     * Process payment (Cash, Visa, or Split).
     * POST /api/v1/invoices/{id}/pay
     */
    public function pay(ProcessPaymentRequest $request, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorizeBranchAccess($request->user(), $invoice->branch_id);

        $paymentsData = $request->validated('payments');
        $updatedInvoice = $this->billingService->processPayment($id, $paymentsData, $request->user()->id);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تسجيل الدفع بنجاح',
            'data'    => $updatedInvoice,
        ]);
    }

    /**
     * Get or create invoice for a specific appointment.
     * GET /api/v1/invoices/appointment/{appointmentId}
     */
    public function forAppointment(Request $request, string $appointmentId): JsonResponse
    {
        $appointment = \App\Models\Appointment::findOrFail($appointmentId);
        $this->authorizeBranchAccess($request->user(), $appointment->branch_id);

        $invoice = $this->billingService->createInvoiceForAppointment($appointment);

        return response()->json([
            'status' => 'success',
            'data'   => $invoice->load(['items', 'patient', 'appointment.doctor', 'branch']),
        ]);
    }

    /**
     * Store new clinic service.
     * POST /api/v1/billing/services
     */
    public function storeService(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'code'          => 'nullable|string|max:50',
            'price'         => 'required|numeric|min:0',
            'branch_id'     => 'nullable|string|exists:branches,id',
        ]);

        if (!empty($validated['branch_id'])) {
            $this->authorizeBranchAccess($request->user(), $validated['branch_id']);
        }

        $service = $this->billingService->createService($validated, $validated['branch_id'] ?? null);

        return response()->json([
            'status'  => 'success',
            'message' => 'تمت إضافة الخدمة بنجاح',
            'data'    => $service,
        ], 201);
    }

    /**
     * Update clinic service price and details.
     * PUT /api/v1/billing/services/{id}
     */
    public function updateService(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'code'          => 'nullable|string|max:50',
            'price'         => 'sometimes|required|numeric|min:0',
            'is_active'     => 'sometimes|boolean',
            'branch_id'     => 'nullable|string|exists:branches,id',
        ]);

        if (!empty($validated['branch_id'])) {
            $this->authorizeBranchAccess($request->user(), $validated['branch_id']);
        }

        $service = $this->billingService->updateService($id, $validated, $validated['branch_id'] ?? null);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تحديث بيانات الخدمة بنجاح',
            'data'    => $service,
        ]);
    }

    /**
     * Deactivate clinic service.
     * DELETE /api/v1/billing/services/{id}
     */
    public function deleteService(Request $request, string $id): JsonResponse
    {
        $this->billingService->deleteService($id);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تعطيل الخدمة بنجاح',
        ]);
    }
}
