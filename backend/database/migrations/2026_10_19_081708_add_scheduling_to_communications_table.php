<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduling an outbound email (module 04 slice S2, spec 12 §S2).
     *
     * A scheduled message has not happened yet, so `occurred_at` becomes nullable and is
     * stamped when it really leaves (rule 4) — the draft rule of spec 11 §2 extended. The
     * worklist reads what is due through `status` + `scheduled_for`, so those two get one
     * index rather than two scans.
     */
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->timestamp('occurred_at')->nullable()->change();
            $table->timestamp('scheduled_for')->nullable()->after('occurred_at');
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_for']);
            $table->dropColumn('scheduled_for');
            $table->timestamp('occurred_at')->nullable(false)->change();
        });
    }
};
