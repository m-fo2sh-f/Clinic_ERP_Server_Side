<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Consultation\CompleteConsultationRequest;
use App\Http\Resources\Consultations\ConsultationResource;
use App\Services\ConsultationService;
use Illuminate\Http\JsonResponse;

class ConsultationController extends Controller
{
    private ConsultationService $consultationService;

    public function __construct(ConsultationService $consultationService)
    {
        $this->consultationService = $consultationService;
    }

    /**
     * Complete a doctor's clinical examination and save the prescription.
     *
     * POST /api/v1/consultations/complete
     */
    public function complete(CompleteConsultationRequest $request): JsonResponse
    {
        $this->authorizeBranchAccess($request->user(), $request->branch_id);

        $result = $this->consultationService->completeConsultation($request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Consultation completed successfully',
            'data'    => new ConsultationResource($result),
        ], 200);
    }
}
