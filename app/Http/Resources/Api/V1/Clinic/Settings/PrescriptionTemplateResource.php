<?php

namespace App\Http\Resources\Api\V1\Clinic\Settings;

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
        $imageUrl = $imagePath ? url('/api/v1/clinic-settings/prescription-template/image/'.basename($imagePath)) : null;
        $directStorageUrl = $imagePath ? asset('storage/'.ltrim($imagePath, '/')) : null;

        $guideImagePath = $data['guide_image_path'] ?? null;
        $guideImageUrl = $guideImagePath ? url('/api/v1/clinic-settings/prescription-template/image/'.basename($guideImagePath)) : null;

        return [
            'layout_mode' => $data['layout_mode'] ?? 'standard_flow',
            'mode' => $data['mode'] ?? 'builtin',
            'paper_size' => $data['paper_size'] ?? 'A5',
            'template_id' => $data['template_id'] ?? 'egyptian_clinic',
            'body_layout' => $data['body_layout'] ?? 'columns',
            'font_size' => $data['font_size'] ?? 'md',
            'primary_color' => $data['primary_color'] ?? '#991b1b',
            'margins' => $data['margins'] ?? [
                'top_cm' => 4.5,
                'bottom_cm' => 3.0,
                'left_cm' => 1.5,
                'right_cm' => 1.5,
            ],
            'freeform_layout' => $data['freeform_layout'] ?? null,
            'show_diagnosis' => (bool) ($data['show_diagnosis'] ?? true),
            'show_vitals' => (bool) ($data['show_vitals'] ?? true),
            'show_refraction' => (bool) ($data['show_refraction'] ?? true),
            'show_borders' => (bool) ($data['show_borders'] ?? true),
            'show_qr' => (bool) ($data['show_qr'] ?? true),
            'show_rx_code' => (bool) ($data['show_rx_code'] ?? true),
            'standard_flow_layout' => $data['standard_flow_layout'] ?? 'side_by_side',
            'vitals_direction' => $data['vitals_direction'] ?? 'horizontal',
            'vitals_config' => $data['vitals_config'] ?? null,
            'auto_print' => (bool) ($data['auto_print'] ?? false),
            'laser_contrast_opt' => (bool) ($data['laser_contrast_opt'] ?? true),
            'header_data' => $data['header_data'] ?? null,
            'footer_data' => $data['footer_data'] ?? null,
            'image_path' => $imagePath,
            'image_url' => $imageUrl,
            'custom_image_url' => $imageUrl,
            'background_image' => $imageUrl,
            'guide_image_path' => $guideImagePath,
            'guide_image_url' => $guideImageUrl,
            'direct_storage_url' => $directStorageUrl,
            'is_doctor_override' => (bool) ($data['is_doctor_override'] ?? false),
            'doctor_id' => $data['doctor_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'branch_doctors' => $data['branch_doctors'] ?? [],
        ];
    }
}
