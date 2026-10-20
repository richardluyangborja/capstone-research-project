<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a generated follow-up came from (module 04 slice S4, spec 12 §3, §11).
     *
     * The key is `renewal:{contract}`, `check_in:{company}:{ISO-week}` or
     * `stale_lead:{lead}:{ISO-week}`. It is what makes the generator idempotent — a run adds a
     * task only where no task already holds the key — so it carries a unique index rather than
     * living as a convention. A hand-written task leaves it null, and a unique index tolerates
     * any number of nulls on both Postgres and SQLite.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('source_key')->nullable()->unique()->after('communication_id');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });
    }
};
