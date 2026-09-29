<?php

namespace App\Rules;

use App\Support\VisitorPasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The visitor password policy as a validation rule - see VisitorPasswordPolicy. */
class VisitorPassword implements ValidationRule
{
    /** @param string[] $personal values the password must not be built from */
    public function __construct(private array $personal = [])
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Laravel runs every rule in a set, so this one cannot assume the
        // 'string' beside it passed. A password posted as an array is that
        // rule's to refuse; casting it here threw an ErrorException that
        // reached the caller as a 500 with a stack trace in the body.
        if (!is_scalar($value)) {
            return;
        }

        $code = VisitorPasswordPolicy::check((string) $value, $this->personal);

        if ($code !== null) {
            $fail(VisitorPasswordPolicy::message($code));
        }
    }
}
