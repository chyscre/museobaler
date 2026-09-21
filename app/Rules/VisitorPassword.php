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
        $code = VisitorPasswordPolicy::check((string) $value, $this->personal);

        if ($code !== null) {
            $fail(VisitorPasswordPolicy::message($code));
        }
    }
}
