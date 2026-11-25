<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VODO Vault review with Himalaya (MOM, 24 Nov 2026).
 *
 *  - Master data: countries, SKUs (each under a brand), content agencies. Documents
 *    point at one SKU and one country; agency users belong to one agency, and work
 *    sent to "the Content Agency" now names which agency.
 *  - documents.status 'pending_closure': the Chairperson signed off (approved, or
 *    approved with changes) and the job waits on the Brand Manager's final approval
 *    and closure before it is Approved for Production.
 *  - document_stage_settings.due_at: the Brand Manager can set an exact due date/time
 *    on any stage, including ones not yet reached.
 *  - IST everywhere: timestamps written so far were UTC; they are moved to IST so the
 *    app (now on Asia/Kolkata) reads them correctly. Skipped when the app timezone is
 *    still UTC, or with TZ_SHIFT_EXISTING=false (e.g. data already in IST).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 10)->nullable()->unique(); // ISO code, drives the reference number prefix
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 100)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['brand_id', 'name']);
        });

        Schema::create('content_agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('content_agency_id')->nullable()->after('designation')->constrained('content_agencies')->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('sku_id')->nullable()->after('brand_id')->constrained('skus')->nullOnDelete();
            $table->foreignId('country_id')->nullable()->after('sku_id')->constrained('countries')->nullOnDelete();
        });

        Schema::table('document_work_tasks', function (Blueprint $table) {
            $table->foreignId('content_agency_id')->nullable()->after('team')->constrained('content_agencies')->nullOnDelete();
        });

        Schema::table('document_stage_settings', function (Blueprint $table) {
            $table->unsignedInteger('due_hours')->nullable()->change();
            $table->timestamp('due_at')->nullable()->after('due_hours');
        });

        DB::statement("ALTER TABLE documents MODIFY status ENUM(
            'draft', 'in_review', 'approved_with_changes_pending', 'draft_aligned', 'pending_closure', 'rejected',
            'approved', 'approved_for_production', 'approved_for_distribution',
            'pending_expiration', 'expired', 'superseded', 'obsolete', 'archived'
        ) DEFAULT 'draft'");

        $this->backfillCountries();
        $this->shiftTimestampsToAppTimezone();
    }

    /** Countries already typed on documents become the starting master list. */
    protected function backfillCountries(): void
    {
        $names = DB::table('documents')->whereNotNull('countries')->pluck('countries')
            ->flatMap(fn ($json) => (array) json_decode($json, true))
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique(fn ($n) => strtolower($n))
            ->values();

        foreach ($names as $name) {
            DB::table('countries')->insertOrIgnore([
                'name' => $name,
                'code' => strlen($name) <= 3 ? strtoupper($name) : null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function shiftTimestampsToAppTimezone(): void
    {
        $tz = config('app.timezone');
        if (! env('TZ_SHIFT_EXISTING', true) || in_array($tz, ['UTC', 'Etc/UTC', '+00:00'], true)) {
            return;
        }

        $minutes = (int) round((new DateTimeZone($tz))->getOffset(new DateTime('now', new DateTimeZone('UTC'))) / 60);
        if ($minutes === 0) {
            return;
        }

        $skipTables = ['migrations', 'jobs', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'job_batches'];

        $columns = DB::select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('timestamp', 'datetime')"
        );

        foreach (collect($columns)->groupBy('t') as $table => $cols) {
            if (in_array($table, $skipTables, true)) {
                continue;
            }
            $sets = $cols->map(fn ($col) => "`{$col->c}` = IF(`{$col->c}` IS NULL, NULL, DATE_ADD(`{$col->c}`, INTERVAL {$minutes} MINUTE))")->implode(', ');
            DB::statement("UPDATE `{$table}` SET {$sets}");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE documents SET status = 'approved_for_production' WHERE status = 'pending_closure'");
        DB::statement("ALTER TABLE documents MODIFY status ENUM(
            'draft', 'in_review', 'approved_with_changes_pending', 'draft_aligned', 'rejected',
            'approved', 'approved_for_production', 'approved_for_distribution',
            'pending_expiration', 'expired', 'superseded', 'obsolete', 'archived'
        ) DEFAULT 'draft'");

        Schema::table('document_stage_settings', function (Blueprint $t) {
            $t->dropColumn('due_at');
        });
        Schema::table('document_work_tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('content_agency_id'));
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('country_id');
            $t->dropConstrainedForeignId('sku_id');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('content_agency_id'));
        Schema::dropIfExists('content_agencies');
        Schema::dropIfExists('skus');
        Schema::dropIfExists('countries');
    }
};
