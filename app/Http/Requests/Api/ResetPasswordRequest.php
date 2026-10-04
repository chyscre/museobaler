<?php

namespace App\Http\Requests\Api;

use App\Models\Visitor;
use App\Rules\VisitorPassword;

/** POST /api/v1/visitors/password/reset. */
class ResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        // The same personal-information check as sign-up, against the
        // account being reset. An unknown email checks against nothing and
        // is refused by the controller once the token fails to match.
        $visitor = Visitor::where('email', $this->scalarInput('email'))->first();

        return [
            'email'       => ['required', 'string', 'email'],
            'reset_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'password'    => [
                'required', 'string', 'confirmed',
                new VisitorPassword(self::personal($visitor)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reset_token.*'      => 'This reset has expired. Please ask for a new code.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }

    /** @return string[] what a visitor's password must not be built from */
    public static function personal(?Visitor $visitor): array
    {
        if ($visitor === null) return [];

        return [
            (string) $visitor->first_name,
            (string) $visitor->last_name,
            (string) $visitor->middle_name,
            strstr((string) $visitor->email, '@', true) ?: '',
        ];
    }
}
