<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A logged communication also writes an `Activity` mirror so the existing lead/company/
     * deal timelines stay the merged feed (module 03, ADR-0017 §2). The column points back
     * at the detail record; the mirror carries no copy of the body.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->ulid('communication_id')->nullable()->after('subject_id');

            $table->index('communication_id');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['communication_id']);
            $table->dropColumn('communication_id');
        });
    }
};
