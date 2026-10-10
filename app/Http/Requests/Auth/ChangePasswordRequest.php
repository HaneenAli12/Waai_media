<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\ActivityLogger;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ! is_null($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        if ($validator->errors()->has('current_password')) {
            app(ActivityLogger::class)->log(
                $this->user()?->id,
                'auth.change_password_failed',
                $this,
                ['reason' => 'invalid_current_password']
            );
        }

        parent::failedValidation($validator);
    }
}
