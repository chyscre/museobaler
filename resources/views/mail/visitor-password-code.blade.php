Hello{{ $firstName !== '' ? ' ' . $firstName : '' }},

Your code to reset your {{ config('app.name') }} password is:

{{ $code }}

Type it into the app within {{ $minutes }} minutes. It works once.

If you did not ask for this, ignore this email - your password stays as it is.
