<?php

use App\Models\MuseumInfo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admission fee becomes a museum setting.
     *
     * Until now the fee was a number written into the code in half a dozen
     * places - the visitor model, the visitor API, the visitor app, the desk
     * screen - while Museum Info carried a free-text "Admission" line that
     * said whatever someone last typed ("Free for all visitors", on an
     * install that charges 50 pesos). The two disagreed on the poster, on the
     * About screen, and at the desk.
     *
     * One number, edited on the Museum Info page, now drives all of it. The
     * old text column stays and is kept in step with the number, so the
     * sentence every screen shows is generated from the fee rather than typed.
     */
    public function up(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->decimal('admission_fee', 8, 2)
                ->default(MuseumInfo::DEFAULT_ADMISSION_FEE)
                ->after('email');
        });

        DB::table('museum_info')->update([
            'admission_fee' => MuseumInfo::DEFAULT_ADMISSION_FEE,
            'admission'     => MuseumInfo::admissionSentence(MuseumInfo::DEFAULT_ADMISSION_FEE),
        ]);
    }

    public function down(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->dropColumn('admission_fee');
        });
    }
};
