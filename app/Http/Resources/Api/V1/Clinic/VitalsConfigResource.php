<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Clinic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VitalsConfigResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;
        $config = $data['config'] ?? [];

        $fields = $config['fields'] ?? ($config ?: []);
        $enabledKeys = $config['enabled_keys'] ?? [];
        $customVitals = $config['custom_vitals'] ?? [];

        // If enabled_keys not provided directly, compute from fields
        if (empty($enabledKeys) && is_array($fields)) {
            foreach ($fields as $field) {
                if (is_array($field) && isset($field['key']) && ($field['is_enabled'] ?? true)) {
                    $enabledKeys[] = (string) $field['key'];
                }
            }
        }

        // If custom_vitals not provided directly, compute from fields
        if (empty($customVitals) && is_array($fields)) {
            foreach ($fields as $field) {
                if (is_array($field) && ! empty($field['is_custom'])) {
                    $customVitals[] = [
                        'key' => (string) ($field['key'] ?? ''),
                        'label' => (string) ($field['label'] ?? ''),
                        'unit' => (string) ($field['unit'] ?? ''),
                        'placeholder' => (string) ($field['placeholder'] ?? ''),
                    ];
                }
            }
        }

        $enableRefraction = (bool) ($config['enable_refraction'] ?? $config['refraction_card_enabled'] ?? false);

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'doctor_id' => $data['doctor_id'] ?? null,
            'is_doctor_override' => (bool) ($data['is_doctor_override'] ?? false),
            'refraction_card_enabled' => $enableRefraction,
            'enable_refraction' => $enableRefraction,
            'enabled_keys' => $enabledKeys ?: ['blood_pressure', 'heart_rate', 'temperature', 'blood_glucose', 'weight', 'height', 'oxygen_saturation'],
            'custom_vitals' => $customVitals ?: [],
            'fields' => $fields,
            'branch_doctors' => $data['branch_doctors'] ?? [],
        ];
    }
}
