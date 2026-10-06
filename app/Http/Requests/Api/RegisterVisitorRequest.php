<?php

namespace App\Http\Requests\Api;

use App\Rules\VisitorPassword;
use App\Support\Admission;
use App\Support\AuroraTowns;
use App\Support\BalerBarangays;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/visitors - a new account from the app's sign-up.
 *
 * SECURITY: the fee and payment status are NEVER taken from the request -
 * a visitor could otherwise post admission_fee=0 and skip paying. They are
 * derived in the controller from the validated visitor_type and discount
 * category alone. Locals claim free admission, which is for residents of
 * Baler (or of Aurora, if the museum has widened it), so a Local must name
 * one of Baler's barangays or one of Aurora's towns accordingly.
 */
class RegisterVisitorRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $email     = $this->scalarInput('email');
        $localPart = strstr($email, '@', true) ?: $email;

        $local           = $this->input('visitor_type') === 'Local';
        $localByBarangay = $local && Admission::scope() === 'baler';
        $localByTown     = $local && Admission::scope() === 'aurora';

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
            // Signing up through Google: this token stands in for the
            // password, and the email it was issued for wins over the form's.
            'google_signup' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'password'     => [
                'exclude_with:google_signup',
                'required', 'string', 'confirmed',
                new VisitorPassword([
                    $this->scalarInput('first_name'),
                    $this->scalarInput('last_name'),
                    $localPart,
                    $this->scalarInput('middle_name'),
                ]),
            ],
            // Which of these a local must give depends on the resident scope:
            // a Baler barangay, or an Aurora town.
            'barangay'     => [
                Rule::requiredIf(fn () => $localByBarangay),
                'nullable', 'string',
                // is_scalar first, for the same reason as country below: a
                // barangay posted as an array is the 'string' rule's to
                // refuse, and isOne() is typed ?string, so handing it one was
                // a TypeError and a 500 where a 422 was owed.
                fn ($attr, $value, $fail) => $localByBarangay
                    && (!is_scalar($value ?? '') || !BalerBarangays::isOne($value === null ? null : (string) $value))
                    ? $fail('Please select your barangay.')
                    : null,
            ],
            'city'         => [
                Rule::requiredIf(fn () => $localByTown),
                'nullable', 'string', 'max:100',
                fn ($attr, $value, $fail) => $localByTown
                    && (!is_scalar($value ?? '') || !AuroraTowns::isOne($value === null ? null : (string) $value))
                    ? $fail('Please select your town.')
                    : null,
            ],
            // A category the visitor claims (senior citizen, PWD...). Only
            // the id is taken; the price is worked out on the server.
            'discount_id'  => [
                'nullable', 'integer',
                function ($attr, $value, $fail) {
                    if ($this->input('visitor_type') === 'Local' || $value === null || $value === '') {
                        return;
                    }
                    $error = Admission::refusal(Admission::discount($value), $this->scalarInput('age'));
                    if ($error !== null) {
                        $fail($error);
                    }
                },
            ],
            'province'     => ['nullable', 'string', 'max:100'],
            'country'      => [
                'nullable', 'string', 'max:100',
                // is_scalar first: 'string' above has already refused a country
                // sent as an array, and casting one here would throw before
                // that 422 could be assembled.
                fn ($attr, $value, $fail) => $this->input('visitor_type') === 'Foreign'
                    && is_scalar($value ?? '')
                    && (trim((string) $value) === '' || strcasecmp(trim((string) $value), 'Philippines') === 0)
                    ? $fail('Please tell us which country you are visiting from.')
                    : null,
            ],
            // Blank unless joining a party the desk signed in. Codes use no
            // 0/O or 1/I and are compared upper-case, so what the desk read
            // out matches what was typed.
            'group_code'   => ['nullable', 'string', 'regex:/^[A-Za-z2-9]{4,8}$/'],
            // Free people who came with them, as counts per category id -
            // the same shape as PUT /visitors/me/companions. Which categories
            // qualify is the server's call (Admission::companionCounts).
            'companions'   => ['nullable', 'array'],
            'companions.*' => ['integer', 'min:0', 'max:' . \App\Models\Visitor::MAX_COMPANIONS],
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
            'city.required'        => 'Please select your town.',
            'group_code.regex'     => 'That group code was not found for today. Check it with the person who signed you in at the desk.',
        ];
    }
}
