<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The survey library (module 05 slice S1, spec 13, ADR-0019 §1).
     *
     * A survey is a reusable template: invitations and responses (slice S2) point at it, never at
     * a copy of it, which is why the question set freezes once one has gone out. There is no
     * `reference` — a survey is picked by name, like a message template.
     */
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table): void {
            // ULID primary key — route key (ADR-0007)
            $table->ulid('id')->primary();

            $table->string('name');
            $table->text('description')->nullable();

            // Drafts are written before they are exposed: only an active survey can be sent
            // (slice S2), which is what this switch means.
            $table->boolean('is_active')->default(false);

            // Who authored it (display only). A survey outlives its author.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The name is unique among *live* rows only, which a database index cannot say while
            // soft deletes are involved — the request enforces it and `restore` re-checks it.
            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
