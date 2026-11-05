<?php

namespace App\Services\Clinic;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrescriptionImageStorageService
{
    protected string $disk = 'public_shared';

    /**
     * Store a valid uploaded prescription template image safely with a random UUID.
     * Uses MIME-derived extension to prevent extension spoofing.
     */
    public function storeImage(UploadedFile $file, string $tenantId): string
    {
        $safeTenantId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId);
        if (empty($safeTenantId)) {
            throw new \InvalidArgumentException('سياق المستأجر غير صالح لتخزين الصورة.');
        }

        // Derive trusted extension strictly from verified MIME type
        $mime = $file->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new \InvalidArgumentException("صيغة الصورة غير مدعومة [{$mime}]. يُسمح فقط بصيغ JPG و PNG و WebP."),
        };

        $filename = Str::uuid()->toString().'.'.$extension;
        $relativeDirectory = "tenants/{$safeTenantId}/templates";

        return Storage::disk($this->disk)->putFileAs($relativeDirectory, $file, $filename);
    }

    /**
     * Safely delete a file from disk if it exists without throwing exceptions,
     * ensuring tenant boundary isolation and preventing directory traversal.
     */
    public function deleteImage(?string $path, ?string $tenantId = null): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $cleanPath = trim($path);

        // Prevent directory traversal or absolute path injection
        if (str_contains($cleanPath, '..') || str_starts_with($cleanPath, '/') || str_starts_with($cleanPath, '\\')) {
            return false;
        }

        // Enforce tenant boundary if tenantId is provided
        if ($tenantId !== null) {
            $safeTenantId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId);
            $expectedPrefix = "tenants/{$safeTenantId}/templates/";

            if (! str_starts_with($cleanPath, $expectedPrefix)) {
                // Allow legacy paths only if explicitly located in legacy templates folder without traversal
                if (! str_starts_with($cleanPath, 'templates/')) {
                    return false;
                }
            }
        }

        if (Storage::disk($this->disk)->exists($cleanPath)) {
            return Storage::disk($this->disk)->delete($cleanPath);
        }

        // Fallback for legacy paths in default public disk
        if (Storage::disk('public')->exists($cleanPath)) {
            return Storage::disk('public')->delete($cleanPath);
        }

        return false;
    }

    /**
     * Resolve public absolute URL for image serving via the secure streaming endpoint.
     */
    public function resolveUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return url('/api/v1/clinic-settings/prescription-template/image/'.basename($path));
    }

    /**
     * Resolve physical file path on the server for secure streaming, preventing path traversal.
     */
    public function resolvePhysicalPath(string $filename, string $tenantId): string
    {
        $safeTenantId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId);
        if (empty($safeTenantId)) {
            abort(404, 'Template image not found.');
        }

        $cleanFilename = basename($filename);

        // Strict filename validation: alphanumeric/uuid characters and allowed image extension only
        if (! preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|webp)$/i', $cleanFilename)) {
            abort(404, 'Template image not found.');
        }

        $sharedPath = "tenants/{$safeTenantId}/templates/{$cleanFilename}";

        if (Storage::disk($this->disk)->exists($sharedPath)) {
            return Storage::disk($this->disk)->path($sharedPath);
        }

        $legacyPath = 'templates/'.$cleanFilename;
        if (Storage::disk('public')->exists($legacyPath)) {
            return Storage::disk('public')->path($legacyPath);
        }

        abort(404, 'Template image not found.');
    }
}
