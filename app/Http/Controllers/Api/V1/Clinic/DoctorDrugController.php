<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Clinic\DoctorDrug\SearchDrugRequest;
use App\Http\Requests\Api\V1\Clinic\DoctorDrug\StoreDoctorDrugRequest;
use App\Http\Requests\Api\V1\Clinic\DoctorDrug\UpdateDoctorDrugRequest;
use App\Http\Resources\Api\V1\Clinic\DrugCatalogResource;
use App\Models\DoctorDrug;
use App\Services\Clinic\Medications\DoctorDrugCatalogService;
use App\Services\Clinic\Medications\DrugSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DoctorDrugController extends Controller
{
    /**
     * Search drugs combining doctor's personal frequent list with central Egyptian catalog.
     */
    public function search(SearchDrugRequest $request, DrugSearchService $searchService): AnonymousResourceCollection
    {
        $results = $searchService->search(
            $request->input('query'),
            $request->user(),
            (int) $request->input('limit', 15)
        );

        return DrugCatalogResource::collection($results);
    }

    /**
     * List doctor's personal prescribing catalog.
     */
    public function index(Request $request, DoctorDrugCatalogService $catalogService): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DoctorDrug::class);

        $drugs = $catalogService->listForDoctor($request->user(), $request->all());

        return DrugCatalogResource::collection($drugs);
    }

    /**
     * Store a new custom drug or default dosage in doctor's catalog.
     */
    public function store(StoreDoctorDrugRequest $request, DoctorDrugCatalogService $catalogService): JsonResponse
    {
        $this->authorize('create', DoctorDrug::class);

        $drug = $catalogService->createForDoctor($request->user(), $request->validated());

        return (new DrugCatalogResource($drug))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing drug in doctor's catalog.
     */
    public function update(
        UpdateDoctorDrugRequest $request,
        DoctorDrug $doctorDrug,
        DoctorDrugCatalogService $catalogService
    ): DrugCatalogResource {
        $this->authorize('update', $doctorDrug);

        $updated = $catalogService->update($doctorDrug, $request->validated());

        return new DrugCatalogResource($updated);
    }

    /**
     * Delete a drug from doctor's catalog.
     */
    public function destroy(
        DoctorDrug $doctorDrug,
        DoctorDrugCatalogService $catalogService
    ): JsonResponse {
        $this->authorize('delete', $doctorDrug);

        $catalogService->delete($doctorDrug);

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الدواء بنجاح من قائمتك الخاصة.',
        ]);
    }

    /**
     * Toggle favorite star for a drug.
     */
    public function toggleFavorite(
        DoctorDrug $doctorDrug,
        DoctorDrugCatalogService $catalogService
    ): DrugCatalogResource {
        $this->authorize('update', $doctorDrug);

        $updated = $catalogService->toggleFavorite($doctorDrug);

        return new DrugCatalogResource($updated);
    }
}
