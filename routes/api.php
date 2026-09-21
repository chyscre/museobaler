<?php

use App\Http\Controllers\Api\MuseumController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The visitor API
|--------------------------------------------------------------------------
|
| What the museum app on a visitor's phone talks to. Every route is under
| /api/v1 and answers JSON. Three tiers:
|
|   public            no token needed - the About screen, the notice board
|   visitor.auth      a signed-in visitor - their own admission status
|   visitor.cleared   signed in AND cleared by the desk - the museum itself:
|                     exhibits, scans, recognition, the survey
|
| The gate is the whole point of the token: exhibit content is what the
| admission fee pays for, so it is released only once staff has collected
| the fee or sighted a residency ID. See EnsureVisitorCleared.
|
| This replaced a set of raw-PHP scripts in public/api that talked to the
| same database through mysqli beside the Laravel panel. Shapes are kept
| identical so the app needed only new paths.
|
*/

Route::prefix('v1')->name('api.')->group(function () {

    // -- Public ------------------------------------------------------------
    Route::get('museum', [MuseumController::class, 'show'])->name('museum');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');
});
