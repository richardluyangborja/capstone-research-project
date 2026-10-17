<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 02 (opportunity pipeline) makes the deal record workable: a stage trail
     * (`stage_changed_at`), activity-driven staleness (`last_activity_at`) and a free-text
     * `next_step`. Additive only — no new tables (ADR-0015).
     */
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->timestamp('stage_changed_at')->nullable()->after('stage');
            $table->timestamp('last_activity_at')->nullable()->after('stage_changed_at');
            $table->string('next_step')->nullable()->after('expected_close_date');

            $table->index('owner_id');
            $table->index('expected_close_date');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropIndex(['owner_id']);
            $table->dropIndex(['expected_close_date']);

            $table->dropColumn(['stage_changed_at', 'last_activity_at', 'next_step']);
        });
    }
};
