<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The follow-up worklist reads open tasks ordered by what is due (spec 12 §S1), so the
     * three columns it filters and sorts on get one index instead of three separate scans.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->index(['type', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['type', 'status', 'due_at']);
        });
    }
};
