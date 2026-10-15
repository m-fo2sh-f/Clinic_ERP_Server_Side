<?php

namespace App\Http\Requests\Api\V1\Clinic;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrescriptionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:letterhead,builtin,custom_image'],
            'paper_size' => ['required', 'string', 'in:A5,A4'],
            'margins' => ['nullable', 'array'],
            'margins.top_cm' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'margins.bottom_cm' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'margins.left_cm' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'margins.right_cm' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'template_id' => ['nullable', 'string', 'max:100'],
            'primary_color' => ['nullable', 'string', 'max:30'],
            'show_diagnosis' => ['sometimes', 'boolean'],
            'show_vitals' => ['sometimes', 'boolean'],
            'show_qr' => ['sometimes', 'boolean'],
            'show_rx_code' => ['sometimes', 'boolean'],
            'auto_print' => ['sometimes', 'boolean'],
            'laser_contrast_opt' => ['sometimes', 'boolean'],
            'header_data' => ['nullable', 'array'],
            'header_data.title' => ['nullable', 'string', 'max:255'],
            'header_data.specialty' => ['nullable', 'string', 'max:255'],
            'header_data.degrees' => ['nullable', 'string', 'max:255'],
            'header_data.phones' => ['nullable', 'array'],
            'header_data.phones.*' => ['string', 'max:50'],
            'header_data.address' => ['nullable', 'string', 'max:500'],
            'doctor_override' => ['nullable', 'boolean'],
            'image_path' => ['nullable', 'string', 'max:500'],
            'background_image' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'string'],
        ];
    }
}
