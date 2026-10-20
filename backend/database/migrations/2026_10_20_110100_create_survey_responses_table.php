<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the client answered (module 05 slice S2, spec 13 §Data model, ADR-0019 §6).
     *
     * One response per invitation, immutable once submitted. `csat`, `nps` and `band` are
     * computed from the answers at submit time and stored so the later views can filter in
     * SQL; the aggregates themselves are derived on read (slice S3).
     */
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One answer per send: the token dies with the submission.
            $table->foreignUlid('invitation_id')->unique()->constrained('survey_invitations')->cascadeOnDelete();

            // Denormalised so the response book filters without walking the invitation.
            $table->foreignUlid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->timestamp('submitted_at');

            // Mean of the rating answers (1–5), two decimals. Null when the survey asks none.
            $table->decimal('csat', 3, 2)->nullable();
            // The promoter answer itself (0–10), and what it means.
            $table->unsignedTinyInteger('nps')->nullable();
            $table->string('band')->nullable();

            // Stamped at scoring time: at or below the configured bounds (ADR-0019 §7). The
            // closed loop that acts on it arrives with slice S4.
            $table->boolean('needs_attention')->default(false);

            $table->timestamps();

            $table->index('company_id');
            $table->index('survey_id');
            $table->index('submitted_at');
            $table->index('needs_attention');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
