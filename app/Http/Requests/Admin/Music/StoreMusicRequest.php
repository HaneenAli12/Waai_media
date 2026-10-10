<?php

namespace App\Http\Requests\Admin\Music;

use Illuminate\Foundation\Http\FormRequest;

class StoreMusicRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Note: In accordance with architectural specifications, NO application-level
     * maximum file size limit (such as max:15360 or any replacement limit) is enforced here.
     * Upper upload bounds are governed strictly by infrastructure/server settings.
     * Full security validation (extensions, MIME types, and magic bytes) is maintained.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'audio_file' => [
                'required',
                'file',
                'mimes:mp3,wav,ogg,m4a,aac',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,audio/x-m4a,audio/aac',
            ],
        ];
    }
}
