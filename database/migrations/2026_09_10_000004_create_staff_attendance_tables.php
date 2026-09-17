<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STAFF attendance. Deliberately separate from `attendances`, which is
     * VISITOR geofence check-in and means something else entirely.
     *
     * Check-in works like a TOTP: a screen in the staff room shows a QR that
     * is recomputed every 60 seconds from that day's secret. A staff member
     * scans it from their own phone while logged into their own account, so
     * the code never carries an identity and a leaked code cannot check
     * anyone in. Location is verified against the museum geofence on top,
     * which is what stops a code being relayed off-site while still fresh.
     */
    public function up(): void
    {
        // One secret per working day. The displayed code is derived from this,
        // so the secret never leaves the server and expires with the date.
        Schema::create('staff_attendance_days', function (Blueprint $table) {
            $table->date('work_date')->primary();
            $table->string('day_secret', 64);
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id('staff_attendance_id');
            $table->foreignId('staff_id')->constrained('staff', 'staff_id')->cascadeOnDelete();
            $table->date('work_date');
            $table->enum('type', ['in', 'out']);
            $table->timestamp('scanned_at')->useCurrent();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('accuracy')->nullable();       // metres reported by the device
            $table->integer('distance_m')->nullable();     // computed distance from the museum

            // qr     = scanned the staff-room code
            // manual = entered by a person, requires an approved correction
            $table->enum('method', ['qr', 'manual'])->default('qr');
            $table->unsignedBigInteger('recorded_by')->nullable(); // set for manual rows only

            $table->string('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            // One check-in and one check-out per person per day.
            $table->unique(['staff_id', 'work_date', 'type']);
            $table->index('work_date');
        });

        // Without a shift, a timestamp cannot be judged late or absent, and
        // "late" is the whole reason the Tourism office wants this.
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id('schedule_id');
            $table->foreignId('staff_id')->constrained('staff', 'staff_id')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');        // 0 = Sunday ... 6 = Saturday
            $table->time('shift_start');
            $table->time('shift_end');
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->boolean('is_rest_day')->default(false);
            $table->timestamps();

            $table->unique(['staff_id', 'weekday']);
        });

        // Separation of duties: the museum head files a correction, the
        // Tourism office approves it. Neither can fix attendance alone, so
        // every manual row carries two names.
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id('correction_id');
            $table->foreignId('staff_id')->constrained('staff', 'staff_id')->cascadeOnDelete();
            $table->date('work_date');
            $table->enum('type', ['in', 'out']);
            $table->time('requested_time');
            $table->text('reason');

            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at')->useCurrent();

            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('staff_schedules');
        Schema::dropIfExists('staff_attendances');
        Schema::dropIfExists('staff_attendance_days');
    }
};
