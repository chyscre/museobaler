<?php

namespace App\Services;

use RuntimeException;

/**
 * Google said "not right now".
 *
 * Separate from the other refusals because it is the only one worth
 * retrying: nothing is wrong with the request, the key or the text, and the
 * same call will work in a few seconds. Carries how long Google asked us to
 * wait so the form can count it down and try again by itself.
 */
class GeminiBusy extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
