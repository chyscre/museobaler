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
        ];
    }
}
