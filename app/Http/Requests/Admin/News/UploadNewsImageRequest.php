<?php

namespace App\Http\Requests\Admin\News;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadNewsImageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Super Admin, Admin, and Editor roles can upload news images.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }

    /**
     * Prepare input before validation so `image` or `file` field name is supported seamlessly.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->hasFile('image') && $this->hasFile('file')) {
            $this->files->set('image', $this->file('file'));
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(['featured', 'cover', 'editor'])],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'An image file is required.',
            'image.file' => 'The uploaded image must be a valid file.',
            'image.mimes' => 'The image must be a file of type: jpeg, jpg, png, webp.',
            'image.max' => 'The image must not exceed 10 MB.',
        ];
    }
}
