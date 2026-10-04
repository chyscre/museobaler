<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/** POST /api/v1/visitors/verify-email. */
class VerifyEmailRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'code'  => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /** Anything that is not an email and six digits is simply a wrong code. */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => 'code_invalid'], 422));
    }
}
