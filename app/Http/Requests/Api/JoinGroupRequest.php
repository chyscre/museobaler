<?php

namespace App\Http\Requests\Api;

/** POST /api/v1/visitors/me/group - join a party the desk signed in. */
class JoinGroupRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'group_code' => ['required', 'string', 'regex:/^[A-Za-z2-9]{4,8}$/'],
        ];
    }

    /** A code of the wrong shape cannot match any group, and is answered as such. */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['error' => 'group_not_found', 'message' => \App\Http\Controllers\Api\VisitorController::groupErrorMessage('group_not_found')], 422)
        );
    }
}
