<?php

namespace App\Http\Requests\Api;

use App\Rules\VisitorPassword;

/** PUT /api/v1/visitors/me/password. */
class ChangePasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password'         => [
                'required', 'string', 'confirmed', 'different:current_password',
                new VisitorPassword(ResetPasswordRequest::personal($this->user('visitor'))),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Please enter your current password.',
            'password.confirmed'         => 'The two new passwords do not match.',
            'password.different'         => 'Your new password must be different from the current one.',
        ];
    }
}
