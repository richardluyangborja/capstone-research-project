<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One answer inside a response (module 05 slice S2, spec 13, ADR-0019 §2).
     *
     * The value column is chosen by the question's type: a number for `rating`/`nps`, the
     * selected options for the choice types, and free text for `text`. Typed columns are what
     * let the score be computed and the ranges validated instead of trusting a JSON blob.
     */
    public function up(): void
    {
        Schema::create('survey_answers', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->foreignUlid('question_id')->constrained('survey_questions')->cascadeOnDelete();

            $table->unsignedTinyInteger('value_number')->nullable();
            $table->text('value_text')->nullable();
            $table->json('value_options')->nullable();

            $table->timestamps();

            // One answer per question per response — the submission replaces nothing.
            $table->unique(['response_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_answers');
    }
};
