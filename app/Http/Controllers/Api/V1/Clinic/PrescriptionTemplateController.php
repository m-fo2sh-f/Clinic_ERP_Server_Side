<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Clinic\UpdatePrescriptionTemplateRequest;
use App\Http\Requests\Api\V1\Clinic\UploadPrescriptionImageRequest;
use App\Http\Resources\Api\V1\Clinic\PrescriptionTemplateResource;
use App\Models\Branch;
use App\Services\Clinic\PrescriptionTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrescriptionTemplateController extends Controller
{
    public function __construct(
        protected PrescriptionTemplateService $templateService
    ) {}

    /**
     * Display the current prescription template settings.
     */
    public function show(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $user = $request->user();

        $template = $this->templateService->getTemplateForBranchAndUser($branchId, $user);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => new PrescriptionTemplateResource($template),
        ]);
    }

    /**
     * Update the prescription template settings.
     */
    public function update(UpdatePrescriptionTemplateRequest $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $user = $request->user();

        $template = $this->templateService->updateTemplate($branchId, $user, $request->validated());

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => 'تم تحديث قالب الروشتة بنجاح',
            'data' => new PrescriptionTemplateResource($template),
        ]);
    }

    /**
     * Upload an image/letterhead for the template.
     */
    public function uploadImage(UploadPrescriptionImageRequest $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $user = $request->user();
        $file = $request->file('image') ?: $request->file('file');

        $doctorOverride = $request->has('doctor_override') ? $request->boolean('doctor_override') : null;

        $template = $this->templateService->uploadTemplateImage($branchId, $user, $file, $doctorOverride);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => 'تم رفع صورة القالب بنجاح',
            'data' => new PrescriptionTemplateResource($template),
        ]);
    }

    /**
     * Delete the template image.
     */
    public function deleteImage(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $user = $request->user();
        $doctorOverride = $request->has('doctor_override') ? $request->boolean('doctor_override') : null;

        $template = $this->templateService->deleteTemplateImage($branchId, $user, $doctorOverride);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => 'تم حذف صورة القالب بنجاح',
            'data' => new PrescriptionTemplateResource($template),
        ]);
    }

    /**
     * Serve a prescription template image safely without CORS issues.
     */
    public function serveImage(string $filename): BinaryFileResponse
    {
        $fullPath = $this->templateService->getImagePath($filename);
        $mime = mime_content_type($fullPath) ?: 'image/png';

        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Resolve the active branch ID and enforce authorization.
     */
    protected function resolveBranchId(Request $request): string
    {
        $branchId = $request->header('X-Branch-ID')
            ?: ($request->input('branch_id')
            ?: (app()->bound('active_branch_id') ? app('active_branch_id') : null));

        if (! $branchId) {
            $user = $request->user();
            if ($user && method_exists($user, 'branches')) {
                $branchId = $user->branches()->value('branches.id');
            }
        }

        if (! $branchId) {
            $branchId = Branch::value('id');
        }

        if (! $branchId) {
            abort(400, 'Branch context is required.');
        }

        $this->authorizeBranchAccess($request->user(), $branchId);

        return (string) $branchId;
    }
}
