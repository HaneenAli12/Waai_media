<?php

namespace App\Http\Requests\Admin\Blog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadBlogImageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Super Admin, Admin, and Editor roles can upload blog images.
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
            'image.required' => 'An image file is required for upload.',
            'image.file' => 'The uploaded asset must be a valid file.',
            'image.mimes' => 'The image must be an approved format: JPEG, JPG, PNG, or WebP.',
            'image.max' => 'The image size cannot exceed 10 MB.',
            'type.in' => 'The image type must be one of: featured, cover, or editor.',
        ];
    }
}
