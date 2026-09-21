<?php

use App\Models\Staff;
use App\Models\Visitor;

return [
    'defaults' => [
        'guard'     => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        // The museum app on a visitor's phone. Stateless: a bearer token
        // issued at sign-in, resolved to a Visitor row on every request.
        // The driver is registered in AppServiceProvider::visitorGuard().
        'visitor' => [
            'driver'   => 'visitor-token',
            'provider' => 'visitors',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => env('AUTH_MODEL', Staff::class),
        ],

        'visitors' => [
            'driver' => 'eloquent',
            'model'  => Visitor::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            // A reset link is a password that arrives by email. Thirty
            // minutes is long enough to read the mail and act on it, and
            // short enough that a message found later in a shared inbox is
            // already dead. (There is no self-service reset screen today -
            // Tourism issues passwords by hand - but the broker is wired,
            // so the ceiling is set for whoever adds one.)
            'expire'   => 30,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
