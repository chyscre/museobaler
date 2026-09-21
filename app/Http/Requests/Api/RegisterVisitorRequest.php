<?php

namespace App\Http\Requests\Api;

use App\Rules\VisitorPassword;
use App\Support\BalerBarangays;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/visitors - a new account from the app's sign-up.
 *
 * SECURITY: the fee and payment status are NEVER taken from the request -
 * a visitor could otherwise post admission_fee=0 and skip paying. They are
 * derived in the controller from the validated visitor_type alone. Locals
 * claim free admission, which is for Baler residents only, so a Local must
 * name one of Baler's barangays; the town and province follow from that.
 */
class RegisterVisitorRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $email     = (string) $this->input('email');
        $localPart = strstr($email, '@', true) ?: $email;

        return [
            'first_name'   => ['required', 'string', 'max:100'],
            'last_name'    => ['required', 'string', 'max:100'],
            'middle_name'  => ['nullable', 'string', 'max:100'],
            'age'          => ['nullable', 'integer', 'between:0,120'],
            'sex'          => ['nullable', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'visit_type'   => ['nullable', Rule::in(['Solo', 'Group', 'School', 'Family', 'Walk-in'])],
            'visitor_type' => ['required', Rule::in(['Local', 'Tourist', 'Foreign'])],
            'explore_mode' => ['nullable', Rule::in(['Storyline', 'Free Roam'])],
            // Mandatory: it is how a returning visitor is recognised.
            'email'        => ['required', 'string', 'email', 'max:150'],
            'password'     => [
                'required', 'string', 'confirmed',
                new VisitorPassword([$this->input('first_name'), $this->input('last_name'), $localPart, $this->input('middle_name')]),
            ],
            'barangay'     => [
                Rule::requiredIf(fn () => $this->input('visitor_type') === 'Local'),
                'nullable', 'string',
                fn ($attr, $value, $fail) => $this->input('visitor_type') === 'Local' && !BalerBarangays::isOne($value)
                    ? $fail('Please select your barangay.')
                    : null,
            ],
            'city'         => ['nullable', 'string', 'max:100'],
            'province'     => ['nullable', 'string', 'max:100'],
            'country'      => [
                'nullable', 'string', 'max:100',
                fn ($attr, $value, $fail) => $this->input('visitor_type') === 'Foreign' && (trim((string) $value) === '' || strcasecmp(trim((string) $value), 'Philippines') === 0)
                    ? $fail('Please tell us which country you are visiting from.')
                    : null,
            ],
            // Blank unless joining a party the desk signed in. Codes use no
            // 0/O or 1/I and are compared upper-case, so what the desk read
            // out matches what was typed.
            'group_code'   => ['nullable', 'string', 'regex:/^[A-Za-z2-9]{4,8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'  => 'Please enter your first and last name.',
            'last_name.required'   => 'Please enter your first and last name.',
            '*.max'                => 'That is too long.',
            'email.required'       => 'Please enter a valid email address.',
            'email.email'          => 'Please enter a valid email address.',
            'password.confirmed'   => 'The two passwords do not match.',
            'barangay.required'    => 'Please select your barangay.',
            'group_code.regex'     => 'That group code was not found for today. Check it with the person who signed you in at the desk.',
        ];
    }
}
