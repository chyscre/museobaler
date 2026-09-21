<?php

use App\Http\Controllers\Api\ExhibitController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MuseumController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RecognitionController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\SurveyController;
use App\Http\Controllers\Api\VisitorController;
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

    // -- Accounts ----------------------------------------------------------
    Route::post('visitors', [VisitorController::class, 'register'])
        ->middleware('throttle:visitor-register')
        ->name('visitors.register');
    Route::post('visitors/login', [VisitorController::class, 'login'])
        ->middleware('throttle:visitor-login')
        ->name('visitors.login');
    Route::post('visitors/logout', [VisitorController::class, 'logout'])->name('visitors.logout');

    // -- Signed in: their own standing with the desk --------------------------
    Route::middleware('visitor.auth')->group(function () {
        Route::get('visitors/me', [VisitorController::class, 'status'])->name('visitors.me');
        Route::post('visitors/me/group', [VisitorController::class, 'joinGroup'])->name('visitors.join-group');
    });

    // -- The museum: signed in and cleared by the desk -----------------------
    Route::middleware(['visitor.auth', 'visitor.cleared'])->group(function () {
        Route::post('scans', [ScanController::class, 'store'])->name('scans.store');
        Route::get('survey', [SurveyController::class, 'show'])->name('survey');
        Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');

        // Revalidated on every request, never served stale: the browser keeps
        // the last answer and sends its ETag, and an unchanged list is a 304
        // with no body. Private, because the answer is behind a token.
        Route::get('exhibits', [ExhibitController::class, 'index'])
            ->middleware('cache.headers:private;etag;no_cache')
            ->name('exhibits.index');
        Route::get('exhibits/{code}', [ExhibitController::class, 'show'])
            ->middleware('cache.headers:private;etag;no_cache')
            ->name('exhibits.show');

        Route::post('recognition', [RecognitionController::class, 'search'])
            ->middleware('throttle:visitor-recognition')
            ->name('recognition');
    });
});
