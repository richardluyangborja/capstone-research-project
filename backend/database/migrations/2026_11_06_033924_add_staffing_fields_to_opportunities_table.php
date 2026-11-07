<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staffing demand on the deal (spec 18 S2): requested headcount, the placement type,
     * the target deployment date and the won outcome note. Additive only — the existing
     * pipeline columns and stage rules are unchanged.
     */
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->unsignedInteger('estimated_headcount')->nullable()->after('probability');
            $table->string('service_type')->nullable()->after('estimated_headcount');
            $table->date('target_start_date')->nullable()->after('expected_close_date');
            $table->text('win_note')->nullable()->after('lost_reason');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn(['estimated_headcount', 'service_type', 'target_start_date', 'win_note']);
        });
    }
};
