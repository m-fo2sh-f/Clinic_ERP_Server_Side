<?php

namespace App\Http\Resources\Api\V1\Clinic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        $imagePath = $data['image_path'] ?? null;
        $imageUrl = null;

        if ($imagePath) {
            $filename = basename($imagePath);
            $imageUrl = asset('storage/'.$imagePath);
        }

        return [
            'mode' => $data['mode'] ?? 'builtin',
            'paper_size' => $data['paper_size'] ?? 'A5',
            'template_id' => $data['template_id'] ?? 'internal_medicine',
            'primary_color' => $data['primary_color'] ?? '#0284c7',
            'margins' => $data['margins'] ?? [
                'top_cm' => 4.5,
                'bottom_cm' => 3.0,
                'left_cm' => 1.5,
                'right_cm' => 1.5,
            ],
            'show_diagnosis' => (bool) ($data['show_diagnosis'] ?? true),
            'show_vitals' => (bool) ($data['show_vitals'] ?? true),
            'show_qr' => (bool) ($data['show_qr'] ?? true),
            'show_rx_code' => (bool) ($data['show_rx_code'] ?? true),
            'auto_print' => (bool) ($data['auto_print'] ?? false),
            'laser_contrast_opt' => (bool) ($data['laser_contrast_opt'] ?? true),
            'header_data' => $data['header_data'] ?? null,
            'image_path' => $imagePath,
            'image_url' => $imageUrl,
            'custom_image_url' => $imageUrl,
            'background_image' => $imageUrl,
            'is_doctor_override' => (bool) ($data['is_doctor_override'] ?? false),
            'branch_id' => $data['branch_id'] ?? null,
        ];
    }
}
