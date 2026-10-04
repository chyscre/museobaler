<?php

/**
 * Two visitor accounts for the visitor-app screenshots, created in the
 * throwaway database that tests/screenshots/run.sh builds and deletes.
 *
 * DemoDataSeeder's visitors are desk records: rows with a name and a payment
 * state but no email and no password, because that is what a walk-in signed in
 * at the counter actually is. The app's screens need an account that can sign
 * in, and two different admission states:
 *
 *   demo.visitor  paid, so the museum is unlocked and the exhibit, map and
 *                 profile screens have something to show
 *   demo.waiting  not yet paid, which is the whole point of the waiting screen
 *
 * Run by tinker from run.sh:  php artisan tinker tests/screenshots/demo-visitors.php
 *
 * The addresses are on .test, which cannot receive mail, and the password is
 * the one already used for the demo staff account — it belongs to a database
 * that is a minute from being deleted.
 */

use App\Models\Visitor;

$password = getenv('DEMO_VISITOR_PASSWORD') ?: 'Sh0tsDemo@2026x';
$fee      = Visitor::feeFor('Tourist');

$common = [
    'age'           => 29,
    'sex'           => 'Female',
    'visitor_type'  => 'Tourist',
    'visit_type'    => 'Walk-in',
    'city'          => 'Quezon City',
    'province'      => 'Metro Manila',
    'country'       => 'Philippines',
    'password'      => $password,
    'auth_provider' => 'manual',
    'source'        => 'app',
    'explore_mode'  => 'Storyline',
    'admission_fee' => $fee,
];

// last_visit has to be today. Admission is charged per visit, so signing in
// after a gap runs Visitor::touchReturning(), which resets a Tourist to Unpaid
// and owing the fee again. Without this the "cleared" account would arrive at
// the waiting screen, which is correct behaviour and the wrong screenshot.
Visitor::updateOrCreate(
    ['email' => 'demo.visitor@museobaler.test'],
    $common + [
        'first_name'     => 'Ana',
        'last_name'      => 'Reyes',
        'payment_status' => 'Paid',
        'paid_at'        => now(),
        'last_visit'     => now(),
    ]
);

Visitor::updateOrCreate(
    ['email' => 'demo.waiting@museobaler.test'],
    $common + [
        'first_name'     => 'Miguel',
        'last_name'      => 'Santos',
        'payment_status' => 'Unpaid',
        'paid_at'        => null,
    ]
);

echo 'demo visitors ready (fee ' . $fee . ")\n";
