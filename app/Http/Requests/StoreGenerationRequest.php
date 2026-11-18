<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Drop blank reference-URL rows the UI leaves behind.
        $this->merge([
            'reference_images' => array_values(array_filter((array) $this->input('reference_images', []), fn ($v) => is_string($v) && trim($v) !== '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'string', 'in:low,medium,high'],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be a valid http(s) URL.',
        ];
    }
}
