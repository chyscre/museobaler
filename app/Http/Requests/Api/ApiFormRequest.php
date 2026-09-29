<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A Form Request whose failure the visitor app can act on.
 *
 * Laravel's standard 422 carries `message` and an `errors` map, and both
 * are kept. Two keys are added: `error`, a stable code the app has always
 * switched on, and `field`, the first input that failed, so the sign-up
 * flow can send the visitor back to the screen that owns it (a rejected
 * password is fixed on the welcome screen, not the details form).
 */
abstract class ApiFormRequest extends FormRequest
{
    /** Anyone may attempt these; who they are is decided by the guard, not here. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An input as a string, and an empty string when it is not one.
     *
     * Every field the app sends is a scalar, but anyone can post anything,
     * and casting an array to a string in PHP is an ErrorException rather
     * than a quiet '' - so `{"email": ["a@b.co"]}` answered with a 500 and a
     * stack trace from inside rules(), before a single rule had run. The
     * shape was wrong and 422 was the entire answer owed. On a production
     * install each of those also sends an exception mail, so an unauthorised
     * caller could have this API write its own alerts.
     *
     * A value that is not a scalar has already failed the 'string' rule
     * sitting beside it, which is what produces the 422; this only keeps the
     * cast from throwing on the way there.
     */
    protected function scalarInput(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? (string) $value : '';
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $field  = array_key_first($errors->toArray());

        throw new HttpResponseException(response()->json([
            'error'   => 'validation_failed',
            'field'   => $field,
            'message' => $errors->first(),
            'errors'  => $errors->toArray(),
        ], 422));
    }
}
