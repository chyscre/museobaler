<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Api\ExhibitController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MuseumController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordController;
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
    Route::get('museum/app-qr', [MuseumController::class, 'appQr'])->name('museum.app-qr');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');

    // -- Accounts ----------------------------------------------------------
    Route::post('visitors', [VisitorController::class, 'register'])
        ->middleware('throttle:visitor-register')
        ->name('visitors.register');
    Route::post('visitors/login', [VisitorController::class, 'login'])
        ->middleware('throttle:visitor-login')
        ->name('visitors.login');
    Route::post('visitors/logout', [VisitorController::class, 'logout'])->name('visitors.logout');

    // Proving the email: the 6-digit code from sign-up, and "Resend Code".
    // No session token exists until this succeeds. See EmailVerificationController.
    Route::post('visitors/verify-email', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:visitor-verify-code')
        ->name('visitors.verify-email');
    Route::post('visitors/verify-email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:visitor-verify-resend')
        ->name('visitors.verify-email.resend');

    // Continue with Google: the one-time code from /auth/google/callback.
    Route::post('visitors/google', [GoogleAuthController::class, 'exchange'])
        ->middleware('throttle:visitor-google')
        ->name('visitors.google');

    // Forgot password: a code by email, the code for a reset token, the
    // token for a new password. See Api\PasswordController.
    Route::post('visitors/password/forgot', [PasswordController::class, 'forgot'])
        ->middleware('throttle:visitor-password-forgot')
        ->name('visitors.password.forgot');
    Route::post('visitors/password/verify', [PasswordController::class, 'verify'])
        ->middleware('throttle:visitor-password-code')
        ->name('visitors.password.verify');
    Route::post('visitors/password/reset', [PasswordController::class, 'reset'])
        ->middleware('throttle:visitor-password-code')
        ->name('visitors.password.reset');

    // -- The fence: token read when present, never required -------------------
    Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::patch('attendance/{id}', [AttendanceController::class, 'update'])->whereNumber('id')->name('attendance.update');

    // Media URLs are temporary signed URLs issued by the cleared exhibit
    // endpoints. The media element cannot attach the bearer header, so the
    // signature is the authorization proof for this short-lived request.
    // `signed:relative`, not `signed`: ExhibitController mints these with
    // absolute: false, and the absolute check rebuilds the scheme and host
    // before hashing, so it rejected every URL this app signed itself. The
    // two have to agree about which form was signed.
    Route::get('media/{path}', [MediaController::class, 'show'])
        ->where('path', '.*')
        ->middleware('signed:relative')
        ->name('media');

    // -- Signed in: their own standing with the desk --------------------------
    Route::middleware('visitor.auth')->group(function () {
        Route::get('visitors/me', [VisitorController::class, 'status'])->name('visitors.me');
        Route::post('visitors/me/group', [VisitorController::class, 'joinGroup'])->name('visitors.join-group');
        // Not behind visitor.cleared: the password belongs to the account,
        // not to today's admission.
        Route::put('visitors/me/password', [PasswordController::class, 'change'])
            ->middleware('throttle:visitor-password-change')
            ->name('visitors.password.change');
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
