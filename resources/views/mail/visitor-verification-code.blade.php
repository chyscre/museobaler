Hello{{ $firstName !== '' ? ' ' . $firstName : '' }},

Your code to confirm your {{ config('app.name') }} account is:

{{ $code }}

Type it into the app within {{ $minutes }} minutes. It works once.

If you did not create an account, ignore this email - nothing more will happen.
