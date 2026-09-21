<?php

namespace App\Http\Requests\Api;

use App\Support\ArtaSurvey;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/feedback - the star rating, the ARTA survey, a comment.
 *
 * `answers` is {CODE: value|null} and is checked against the live question
 * bank in the controller, not here: which codes exist and which values
 * each takes is data the Tourism office edits, not a rule to hard-code.
 * An older app build that posts only a star rating is still accepted -
 * with no `answers` key at all, the survey is simply not filed.
 */
class StoreFeedbackRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'rating'       => ['required', 'integer', 'between:1,5'],
            'guide_rating' => ['nullable', 'integer', 'between:0,5'],
            'comment'      => ['nullable', 'string', 'max:2000'],
            'first_name'   => ['nullable', 'string', 'max:100'],
            'last_name'    => ['nullable', 'string', 'max:100'],
            'middle_name'  => ['nullable', 'string', 'max:100'],
            'client_type'  => ['nullable', Rule::in(ArtaSurvey::CLIENT_TYPES)],
            'region'       => ['nullable', Rule::in(ArtaSurvey::REGIONS)],
            'answers'      => ['nullable', 'array'],
        ];
    }
}
