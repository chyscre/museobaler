<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExhibitAiController;
use App\Http\Controllers\ExhibitController;
use App\Http\Controllers\RecognitionController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\VisitorController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MuseumController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DeskController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\AttendanceCorrectionController;
use App\Http\Controllers\TourController;
use App\Http\Controllers\SurveyQuestionController;

// Role shorthands.
//
// Two roles, and three audiences for the routes below:
//
//   $museum   museum staff only — running the museum day to day. The head of
//             tourism is not in the building, so none of this is hers.
//   $tourism  the Tourism office only — accounts, schedules, approvals,
//             audit export. Powers that only make sense from outside.
//   $shared   both — the people, the visitors, the feedback, the reports.
//
// Plain variables, not constants: Tests\TestCase re-requires this file on
// every test's fresh application boot, and PHP constants cannot be redefined
// within one process — a `const` here throws "already defined" on test two.
$museum  = 'role:Administrator';
$tourism = 'role:TourismHead';
$shared  = 'role:TourismHead,Administrator';

// ── Auth ──────────────────────────────────────────────────────
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.post')->middleware('login.throttle');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ── Exhibit image proxy (serves images from public/images/exhibits/) ──
Route::get('/exhibit-image/{filename}', function (string $filename) {
    $filename = basename($filename);
    $path = public_path('images/exhibits/' . $filename);
    if (!file_exists($path)) abort(404);
    $mime = mime_content_type($path) ?: 'image/jpeg';
    return response()->file($path, ['Content-Type' => $mime]);
})->name('exhibit.image')->middleware('auth');

// ── Exhibit audio proxy (serves audio from public/audio/) ──
Route::get('/exhibit-audio/{filename}', function (string $filename) {
    $filename = basename($filename);
    $path = public_path('audio/' . $filename);
    if (!file_exists($path)) abort(404);
    // Translations accept mp3/wav/ogg/m4a — detect the real type instead of
    // always claiming audio/mpeg, which mislabels non-mp3 uploads.
    $mime = mime_content_type($path) ?: 'audio/mpeg';
    return response()->file($path, ['Content-Type' => $mime]);
})->name('exhibit.audio')->middleware('auth');

// ── Recognition photo proxy (serves images from public/images/training/) ──
Route::get('/training-image/{filename}', function (string $filename) {
    $filename = basename($filename);
    $path = public_path(\App\Services\Recognition::DIR . '/' . $filename);
    if (!file_exists($path)) abort(404);
    $mime = mime_content_type($path) ?: 'image/jpeg';
    return response()->file($path, ['Content-Type' => $mime]);
})->name('recognition.photo')->middleware('auth');

// ── Your own account ──────────────────────────────────────────
// Outside the group below on purpose: this is the one screen a staff member
// must be able to reach while still holding the password Tourism handed them,
// and the one screen that has to work on a phone as well as a desk.
Route::middleware(['auth', 'throttle:panel'])->group(function () {
    Route::get('/my/password',  [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/my/password',  [PasswordController::class, 'update'])->name('password.update');
});

// ── Admin (protected) ─────────────────────────────────────────
//
// password.rotate  an account still using the password it was issued goes no
//                  further than the change-password screen.
// desktop          the panel is a desktop tool; a phone session is narrowed
//                  to clocking in, which is the only part that needs one.
// throttle:panel   300 requests a minute per account - see
//                  AppServiceProvider::rateLimitPanel().
Route::middleware(['auth', 'throttle:panel', 'password.rotate', 'desktop'])->group(function () use ($shared, $tourism, $museum) {

    // ══ Museum staff only ═════════════════════════════════════
    // Running the museum: the desk, the exhibits, the map, the guiding, and
    // clocking in. The Tourism office is deliberately shut out of all of it —
    // the head of tourism works from the municipal office, does not stand at
    // the entrance desk, does not write exhibit labels, and does not clock in.
    Route::middleware([$museum])->group(function () {

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/chart/visitors',   [DashboardController::class, 'chartVisitors'])->name('dashboard.chart.visitors');
        Route::get('/dashboard/chart/categories', [DashboardController::class, 'chartCategories'])->name('dashboard.chart.categories');

        // Exhibits
        Route::resource('exhibits', ExhibitController::class);
        Route::get('/exhibits/{exhibit}/modal',     [ExhibitController::class, 'modalShow'])->name('exhibits.modal.show');
        Route::post('/exhibits/{exhibit}/archive',  [ExhibitController::class, 'archive'])->name('exhibits.archive');
        Route::post('/exhibits/{exhibit}/restore',  [ExhibitController::class, 'restore'])->name('exhibits.restore');
        Route::get('/exhibits/{exhibit}/edit-form', [ExhibitController::class, 'modalEdit'])->name('exhibits.modal.edit');
        Route::post('/exhibits/{exhibit}/translations', [ExhibitController::class, 'storeTranslation'])->name('exhibits.translations.store');
        Route::put('/translations/{translation}',       [ExhibitController::class, 'updateTranslation'])->name('exhibits.translations.update');
        Route::delete('/translations/{translation}',    [ExhibitController::class, 'destroyTranslation'])->name('exhibits.translations.destroy');
        Route::post('/exhibits/{exhibit}/gallery',      [ExhibitController::class, 'uploadGallery'])->name('exhibits.gallery.upload');
        Route::delete('/gallery/{image}',               [ExhibitController::class, 'destroyGalleryImage'])->name('exhibits.gallery.destroy');

        // The AI half of the exhibit form: drafts to read and listen to,
        // saved only when the form is.
        //
        // throttle:ai - the only routes here that spend money. See
        // AppServiceProvider::rateLimitAiCalls() for the ceilings.
        Route::middleware('throttle:ai')->group(function () {
            Route::post('/exhibits/ai/translate', [ExhibitAiController::class, 'translate'])->name('exhibits.ai.translate');
            Route::post('/exhibits/ai/narrate',   [ExhibitAiController::class, 'narrate'])->name('exhibits.ai.narrate');
        });

        // QR codes for the display cases
        Route::get('/qr-codes',           [ExhibitController::class, 'qrCodes'])->name('exhibits.qr.panel');
        Route::get('/qr-codes/download',  [ExhibitController::class, 'qrDownloadAll'])->name('exhibits.qr.download');
        Route::get('/qr-codes/{exhibit}', [ExhibitController::class, 'qrSingle'])->name('exhibits.qr.single');

        // Point-and-identify: the photos the model learns from, and the
        // model itself, trained in the browser. The photo pages are also
        // allowed on a phone - see DesktopOnly - because that is where the
        // camera is.
        Route::get('/recognition',                  [RecognitionController::class, 'index'])->name('recognition.index');
        Route::get('/recognition/dataset',          [RecognitionController::class, 'dataset'])->name('recognition.dataset');
        Route::post('/recognition/model',           [RecognitionController::class, 'saveModel'])->name('recognition.model.save');
        Route::get('/recognition/background',       [RecognitionController::class, 'photos'])->name('recognition.background');
        Route::post('/recognition/background',      [RecognitionController::class, 'upload'])->name('recognition.background.upload');
        Route::get('/recognition/{exhibit}',        [RecognitionController::class, 'photos'])->name('recognition.photos');
        Route::post('/recognition/{exhibit}',       [RecognitionController::class, 'upload'])->name('recognition.photos.upload');
        Route::delete('/recognition/photo/{photo}', [RecognitionController::class, 'destroyPhoto'])->name('recognition.photo.destroy');
        Route::post('/recognition/background/remove', [RecognitionController::class, 'destroyMany'])->name('recognition.background.remove');
        Route::post('/recognition/{exhibit}/remove',  [RecognitionController::class, 'destroyMany'])->name('recognition.photos.remove');

        // Front desk — replaces the paper logbook. Three ways in, and only the
        // last has staff typing: the visitor's own phone, the counter tablet,
        // or the desk.
        Route::get('/desk',                      [DeskController::class, 'create'])->name('desk.register');
        // Printable "scan to sign in" poster for the entrance — the way
        // visitors self-register when there is no counter tablet.
        Route::get('/desk/poster',               [DeskController::class, 'poster'])->name('desk.poster');
        Route::post('/desk/visitors',            [DeskController::class, 'store'])->name('desk.visitors.store');
        Route::post('/desk/groups',              [DeskController::class, 'storeGroup'])->name('desk.groups.store');
        Route::post('/desk/groups/{group}/paid', [DeskController::class, 'markGroupPaid'])->name('desk.groups.paid');
        // A local in a party that was charged for everyone. Re-prices the
        // group and, if it had already paid, records the refund.
        Route::post('/desk/groups/{group}/correct', [DeskController::class, 'correctGroup'])->name('desk.groups.correct');

        // Admission handling at the entrance — collecting the fee from
        // tourists and sighting a local's proof of residency.
        Route::post('/visitors/{visitor}/mark-paid', [VisitorController::class, 'markPaid'])->name('visitors.mark-paid');
        Route::post('/visitors/{visitor}/verify-id', [VisitorController::class, 'verifyId'])->name('visitors.verify-id');

        // Guided tours — occasional by design.
        Route::get('/tours',             [TourController::class, 'index'])->name('tours.index');
        Route::post('/tours',            [TourController::class, 'store'])->name('tours.store');
        Route::post('/tours/{tour}/end', [TourController::class, 'end'])->name('tours.end');

        // Filing an attendance correction. Museum staff were on site and can
        // vouch for a colleague; the Tourism office was not, so she reviews
        // rather than files — otherwise one person could do both halves.
        Route::post('/attendance/corrections', [AttendanceCorrectionController::class, 'store'])->name('corrections.store');

        // Own attendance. Only museum staff clock in.
        Route::get('/my/attendance',       [StaffAttendanceController::class, 'mine'])->name('my.attendance');
        Route::post('/my/attendance/scan', [StaffAttendanceController::class, 'scan'])->name('my.attendance.scan');
        // Sets the museum pin from a phone's GPS. Museum Info is a desktop
        // page, and a desktop's idea of where it is comes from Wi-Fi, which
        // once put the pin 1.8 km from the door.
        Route::post('/my/attendance/pin',  [StaffAttendanceController::class, 'setPin'])->name('my.attendance.pin');

        // The staff-room screen. Left running on a tablet; the code it shows
        // rotates every 60 seconds so a photo of it is worthless.
        Route::get('/attendance/kiosk',      [StaffAttendanceController::class, 'kiosk'])->name('attendance.kiosk');
        Route::get('/attendance/kiosk/qr',   [StaffAttendanceController::class, 'kioskQr'])->name('attendance.kiosk.qr');
        Route::get('/attendance/kiosk/tick', [StaffAttendanceController::class, 'kioskTick'])->name('attendance.kiosk.tick');

        // Visitor geofence check-ins from the visitor app — a different table
        // from staff attendance entirely.
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');

        // Museum info, map, and the geofence that the visitor app and the
        // staff check-in both read.
        Route::get('/museum',  [MuseumController::class, 'index'])->name('museum.index');
        Route::get('/map',            [MuseumController::class, 'map'])->name('museum.map');
        Route::post('/map/positions', [MuseumController::class, 'savePositions'])->name('museum.map.positions');
        Route::post('/museum', [MuseumController::class, 'update'])->name('museum.update');

        // Live activity feed for the admin bell.
        Route::get('/notifications/poll', [NotificationController::class, 'poll'])->name('notifications.poll');
    });

    // ══ Shared ════════════════════════════════════════════════
    // What the Tourism office oversees, and what museum staff also need day
    // to day: the people, the visitors, what visitors said, the paperwork.
    Route::middleware([$shared])->group(function () {

        Route::get('/records', [VisitorController::class, 'index'])->name('records.index');

        Route::get('/feedback',                  [FeedbackController::class, 'index'])->name('feedback.index');
        Route::get('/feedback/{feedback}',       [FeedbackController::class, 'show'])->name('feedback.show');
        Route::get('/feedback/{feedback}/modal', [FeedbackController::class, 'modalShow'])->name('feedback.modal.show');

        Route::get('/logs', [LogController::class, 'index'])->name('logs.index');

        Route::get('/staff-attendance',         [StaffAttendanceController::class, 'index'])->name('staff-attendance.index');
        Route::get('/staff-attendance/{staff}', [StaffAttendanceController::class, 'show'])->name('staff-attendance.show');

        // Both roles read the corrections list. Filing one is museum work
        // (further down); approving one is Tourism's (further down still).
        Route::get('/attendance/corrections',  [AttendanceCorrectionController::class, 'index'])->name('corrections.index');

        // The individual reports keep their routes; there is no longer a
        // hub page listing them. Each is reached from the section it belongs
        // to — the logbook from Records, the DTR from Staff Attendance.
        Route::get('/reports/logbook',     [ReportController::class, 'logbook'])->name('reports.logbook');
        Route::get('/reports/dtr/{staff}', [ReportController::class, 'dtr'])->name('reports.dtr');
        Route::get('/reports/visitors',    [ReportController::class, 'visitors'])->name('reports.visitors');
        Route::get('/reports/feedback',    [ReportController::class, 'feedback'])->name('reports.feedback');
        Route::get('/reports/exhibits',    [ReportController::class, 'exhibits'])->name('reports.exhibits');

        // One download endpoint for every report and every format it allows.
        // The `where` is what makes an unknown format a 404 at the router
        // rather than a match that reaches the controller and falls through
        // a match() with no arm for it.
        //
        // SECURITY: `audit` is deliberately absent from this list. The audit
        // trail is Tourism-only and has its own route further down, inside
        // the Tourism group. A wildcard that matched it here would be
        // declared before that group and would win, quietly handing the
        // museum staff the log kept on them.
        Route::get('/reports/{report}/export/{format}', [ReportController::class, 'export'])
            ->where('report', 'logbook|dtr|visitors|exhibits|feedback')
            ->where('format', 'csv|xlsx|docx|pdf')
            ->name('reports.export');

        // What the chosen format will contain, before committing to a
        // download. Same wall as the export above: no audit here either.
        Route::get('/reports/{report}/preview/{format}', [ReportController::class, 'preview'])
            ->where('report', 'logbook|dtr|visitors|exhibits|feedback')
            ->where('format', 'csv|xlsx|docx|pdf')
            ->name('reports.preview');

        // The CSV links that existed before the other formats did. They
        // still serve the file rather than redirecting: a bookmarked export
        // is exactly the kind of thing an office keeps, and anything
        // fetching one on a schedule may not follow a 302.
        Route::get('/reports/logbook/csv',  [ReportController::class, 'logbookCsv'])->name('reports.logbook.csv');
        Route::get('/reports/feedback/csv', [ReportController::class, 'feedbackCsv'])->name('reports.feedback.csv');
    });

    // ══ Tourism office only ═══════════════════════════════════
    // The powers that only make sense from outside the museum.
    //
    // SECURITY: staff accounts live here because the museum is staffed by the
    // LGU tourism office. If museum staff could create accounts, the tier
    // being overseen would be minting its own overseers. Schedules are here
    // because a schedule decides who counts as late, and approvals because a
    // manual attendance row needs a second signature from someone who was not
    // the one who asked for it.
    Route::middleware([$tourism])->group(function () {
        Route::resource('staff', StaffController::class)->except(['show', 'destroy']);
        Route::get('/staff/{staff}/edit-form', [StaffController::class, 'modalEdit'])->name('staff.modal.edit');
        Route::post('/staff/{staff}/toggle',   [StaffController::class, 'toggle'])->name('staff.toggle');

        // Issuing a password is its own act, not a checkbox on the edit form.
        // It gets its own audit entry, and it cannot happen by accident while
        // someone is correcting a spelling.
        Route::post('/staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])->name('staff.reset-password');

        Route::get('/staff-attendance/{staff}/schedule',  [StaffAttendanceController::class, 'schedules'])->name('staff-attendance.schedule');
        Route::post('/staff-attendance/{staff}/schedule', [StaffAttendanceController::class, 'saveSchedules'])->name('staff-attendance.schedule.save');

        Route::post('/attendance/corrections/{correction}/review', [AttendanceCorrectionController::class, 'review'])->name('corrections.review');

        // The audit trail stays Tourism-only: it is the record of what
        // everyone else did, including the museum staff it is kept on.
        Route::get('/reports/audit/export/{format}', [ReportController::class, 'exportAudit'])
            ->where('format', 'csv|pdf')
            ->name('reports.audit.export');

        Route::get('/reports/audit/preview/{format}', [ReportController::class, 'previewAudit'])
            ->where('format', 'csv|pdf')
            ->name('reports.audit.preview');

        Route::get('/reports/audit/csv', [ReportController::class, 'auditCsv'])->name('reports.audit.csv');

        // The visitor survey's question bank. The survey is the ARTA Client
        // Satisfaction Measurement, which the Tourism office files with the
        // LGU, so the office that answers for the numbers owns the questions.
        Route::get('/survey',           [SurveyQuestionController::class, 'index'])->name('survey.index');
        Route::post('/survey',          [SurveyQuestionController::class, 'save'])->name('survey.save');
        Route::post('/survey/restore',  [SurveyQuestionController::class, 'restore'])->name('survey.restore');
    });
});
