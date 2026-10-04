<?php

namespace App\Http\Requests\Api;

/** POST /api/v1/visitors/password/forgot. */
class ForgotPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Please enter a valid email address.',
            'email.email'    => 'Please enter a valid email address.',
        ];
    }
}
