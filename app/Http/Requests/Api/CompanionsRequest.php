<?php

namespace App\Http\Requests\Api;

use App\Models\Visitor;

/**
 * PUT /api/v1/visitors/me/companions - who the visitor brought along free.
 *
 * Counts per category id, e.g. {"companions": {"3": 2, "1": 1}}. Only the
 * counts are taken: which categories are free, and so may be claimed this
 * way, is decided on the server (Admission::companionCounts).
 */
class CompanionsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'companions'   => ['present', 'array'],
            'companions.*' => ['integer', 'min:0', 'max:' . Visitor::MAX_COMPANIONS],
        ];
    }

    public function messages(): array
    {
        return [
            'companions.*.max' => 'That is more than one visitor can bring. Ask the desk to register you as a group.',
        ];
    }
}
