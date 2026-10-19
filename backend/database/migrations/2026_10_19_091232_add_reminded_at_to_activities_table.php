<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reminder marker (module 04 slice S3, spec 12 §3, §10).
     *
     * The due run notifies the owner of each open task that has fallen due and stamps this, so a
     * second run of the same day adds nothing. Rescheduling clears it, which re-arms the task:
     * `00-data-model.md` reserved the column; slice S3 is where it is first written.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
