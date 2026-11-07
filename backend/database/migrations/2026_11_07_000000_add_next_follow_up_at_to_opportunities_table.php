<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The deal's earliest open follow-up (spec 18 S4), maintained beside the lead's column
     * so the board and the table read one value instead of joining the task table.
     */
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->timestamp('next_follow_up_at')->nullable()->index()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropIndex(['next_follow_up_at']);
            $table->dropColumn('next_follow_up_at');
        });
    }
};
