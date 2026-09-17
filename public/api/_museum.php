<?php
/**
 * Museum settings for the visitor API.
 *
 * The admission fee is set on the admin's Museum Info page and lives in
 * museum_info.admission_fee. This file is the plain-mysqli twin of
 * App\Models\MuseumInfo::admissionFee() / admissionSentence() — the visitor
 * API does not boot Laravel, so it reads the row itself. Keep the two in step.
 */

const DEFAULT_ADMISSION_FEE = 50.00;

function museumAdmissionFee($con): float
{
    static $fee = null;
    if ($fee !== null) return $fee;

    $fee = DEFAULT_ADMISSION_FEE;
    try {
        // Column arrives with the 2026_09_13 migration; before it, or on an
        // empty table, the museum charges what it always has.
        $r = @mysqli_query($con, "SELECT admission_fee FROM museum_info LIMIT 1");
        if ($r && ($row = mysqli_fetch_assoc($r)) && $row['admission_fee'] !== null) {
            $fee = (float) $row['admission_fee'];
        }
    } catch (Throwable $e) {}

    return $fee;
}

function museumAdmissionSentence(float $fee): string
{
    if ($fee <= 0) return 'Free for all visitors';
    return 'Baler residents enter free with a valid ID · Visitors ₱' . number_format($fee, 2);
}
