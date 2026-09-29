<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/** POST /api/v1/scans - the visitor opened an exhibit. */
class StoreScanRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'exhibit_id' => ['required', 'integer', Rule::exists('exhibits', 'exhibit_id')],
            'scan_type'  => ['nullable', Rule::in(['qr', 'image'])],
            // When the app was offline at the time. Bounded at both ends: a
            // scan cannot have happened in the future, and a backlog older
            // than two days is not a dead zone any more - it is a phone whose
            // clock is wrong, or a replay, and either way it must not be
            // filed as if it were part of that visit.
            'scanned_at' => ['nullable', 'date', 'after:-2 days', 'before:+5 minutes'],
        ];
    }
}
