<?php

use App\Support\ArtaSurvey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor survey becomes the ARTA Client Satisfaction Measurement form.
 *
 * Questions live in a table rather than in the app, so the Tourism office
 * can reword, reorder, add and retire them without a deploy. Answers are
 * stored one row per question, keyed by the question's code, so a report
 * can be built for any question that ever existed — a deleted question
 * still has its history.
 *
 * The existing feedback.rating / guide_rating / comment columns are kept as
 * they are. The star rating is the quick number on the dashboard; the survey
 * is the formal instrument behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id('question_id');
            // Short stable key: CC1, SQD5, APP_EASE. This is what reports
            // group by, so it never changes once answers reference it.
            $table->string('code', 32)->unique();
            $table->enum('section', ['cc', 'sqd', 'app'])->default('app');
            $table->enum('scale', ['agree5', 'choice'])->default('agree5');
            $table->text('text_fil');
            $table->text('text_en');
            $table->string('hint_fil', 255)->nullable();
            $table->string('hint_en', 255)->nullable();
            // [{value, fil, en, na?}] for scale = choice; null otherwise.
            $table->json('options')->nullable();
            // {"code": "CC1", "in": [1,2,3]} — ask only when that holds.
            $table->json('show_if')->nullable();
            $table->boolean('allow_na')->default(true);
            $table->boolean('default_na')->default(false);
            $table->boolean('required')->default(true);
            // ARTA rows: editable text, fixed code, cannot be deleted.
            $table->boolean('locked')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('feedback_answers', function (Blueprint $table) {
            $table->id('answer_id');
            $table->foreignId('feedback_id')->constrained('feedback', 'feedback_id')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('survey_questions', 'question_id')->nullOnDelete();
            // Denormalised so the answer survives the question being deleted.
            $table->string('code', 32);
            // 1–5 (or the option value); null means N/A.
            $table->tinyInteger('value')->nullable();
            $table->timestamps();

            $table->unique(['feedback_id', 'code']);
            $table->index('code');
        });

        Schema::table('feedback', function (Blueprint $table) {
            // "Uri ng Kliyente" and "Rehiyon" from the paper form. The rest of
            // the header — name, sex, age, date — is already on the visitor.
            $table->enum('client_type', ['citizen', 'business', 'government'])->nullable()->after('comment');
            $table->string('region', 60)->nullable()->after('client_type');
        });

        ArtaSurvey::seedMissing();
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn(['client_type', 'region']);
        });
        Schema::dropIfExists('feedback_answers');
        Schema::dropIfExists('survey_questions');
    }
};
