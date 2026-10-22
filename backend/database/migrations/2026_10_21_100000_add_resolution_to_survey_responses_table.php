<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The closed-loop record on an answer (module 05 slice S4, spec 13 §Data model, ADR-0019 §7).
     *
     * The response itself stays immutable: `resolved_at`, `resolved_by` and `resolution_note` are
     * the one thing written after submission, recording who closed the loop and how. The flag
     * (`needs_attention`) is not cleared — the answer was unhappy; the resolution says it was
     * dealt with, which is what the unresolved views filter on.
     */
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->timestamp('resolved_at')->nullable()->after('needs_attention');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')
                ->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable()->after('resolved_by');

            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->dropIndex(['resolved_at']);
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution_note']);
        });
    }
};
