<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 09 slice T4 (spec 21 §T4, ADR-0033): a rep's disputed attendance, leave balance or
 * commission figure, escalated to their manager instead of being fixed by the CRM.
 *
 * This is its own table rather than an `Activity` because an activity cannot hang off a user
 * (`HasSubjectRecord::subjectTypes()` has no `user`) and carries no category or resolution fields.
 * The row is CRM-internal history: resolving stamps it, nothing deletes it. Core 2 is never written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_discrepancies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category');
            $table->text('explanation');

            // The CRM record the flag is about, when it has one (opportunity / contract, both ULIDs).
            $table->nullableUlidMorphs('subject');

            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')
                ->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable()->after('resolved_by');

            $table->timestamps();

            // The profile reads one employee's flags and filters the open ones.
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_discrepancies');
    }
};
