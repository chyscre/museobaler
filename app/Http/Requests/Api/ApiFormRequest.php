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
