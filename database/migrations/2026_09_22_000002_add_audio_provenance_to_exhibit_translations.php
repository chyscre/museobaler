<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Which text a narration was actually read from.
 *
 * Narration is generated once from the translation as it stood that day,
 * and then the label gets rewritten - a correction, a better description,
 * a whole re-editing pass - and the audio file stays exactly as it was.
 * Nothing on the screen said so, so an exhibit could sit in the museum for
 * months reading a description it no longer has. That is what happened
 * here: two exhibits were narrated in April and rewritten in September.
 *
 * The hash is of the text that was narrated, so "does this audio still
 * match?" becomes an exact question rather than a guess. The timestamp is
 * the fallback for rows made before this existed, backfilled from the
 * filename, which has always carried the second it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exhibit_translations', function (Blueprint $table) {
            $table->timestamp('audio_made_at')->nullable()->after('audio_file');
            $table->string('audio_text_hash', 40)->nullable()->after('audio_made_at');
        });

        foreach (DB::table('exhibit_translations')->whereNotNull('audio_file')->get() as $row) {
            if (preg_match('/_(\d{9,11})\.(wav|mp3|ogg|m4a)$/i', (string) $row->audio_file, $m)) {
                DB::table('exhibit_translations')
                    ->where('translation_id', $row->translation_id)
                    ->update(['audio_made_at' => date('Y-m-d H:i:s', (int) $m[1])]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('exhibit_translations', function (Blueprint $table) {
            $table->dropColumn(['audio_made_at', 'audio_text_hash']);
        });
    }
};
