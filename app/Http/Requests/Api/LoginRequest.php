<?php

namespace App\Http\Requests\Api;

/** POST /api/v1/visitors/login. */
class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * A malformed email is answered like a wrong one: the sign-in form
     * has one error line, and telling "not an email" apart from "no such
     * account" is one more thing an enumeration attempt could learn.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['error' => 'invalid_credentials'], 401)
        );
    }
}
