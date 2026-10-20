<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A survey's ordered questions (module 05 slice S1, spec 13, ADR-0019 §2).
     *
     * `position` is the asking order — 1..n, rewritten whenever the set changes. `options` is the
     * choice list for `single_choice`/`multi_choice` and null for the other three types; the
     * request enforces that, because the column cannot.
     */
    public function up(): void
    {
        Schema::create('survey_questions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Questions belong to their survey and go when it is force-deleted; retiring a survey
            // is a soft delete and keeps them.
            $table->foreignUlid('survey_id')->constrained('surveys')->cascadeOnDelete();

            $table->string('type');
            $table->string('prompt');
            $table->string('help')->nullable();
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('position');
            $table->json('options')->nullable();

            $table->timestamps();

            $table->index(['survey_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_questions');
    }
};
