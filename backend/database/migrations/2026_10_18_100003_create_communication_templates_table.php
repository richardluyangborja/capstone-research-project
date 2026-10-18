<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable wording for the composer (module 03 slice S5, spec 11, ADR-0017 §10).
     *
     * A template is only ever a **prefill**: nothing points back at it once a message is saved,
     * so editing or retiring one can never rewrite history. There is no `reference` — a template
     * is picked by name.
     */
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table): void {
            // ULID primary key — route key (ADR-0007)
            $table->ulid('id')->primary();

            $table->string('name');
            $table->string('channel')->default('email');

            // The title line and the body, both allowed to hold {{merge}} fields.
            $table->string('subject');
            $table->text('body');

            // Retiring one soft-deletes the row; `is_active` is the other knob — "kept, but not
            // offered in the composer's picker".
            $table->boolean('is_active')->default(true);

            // Who wrote it (display only). A template outlives its author.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Name is unique among *live* rows only, which a database unique index cannot say
            // while soft deletes are involved — `StoreCommunicationTemplateRequest` enforces it
            // and `restore` re-checks it.
            $table->index('name');
            $table->index('channel');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_templates');
    }
};
