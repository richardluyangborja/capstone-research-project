<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            // ULID primary key — route key; never an auto-incrementing id (ADR-0007)
            $table->ulid('id')->primary();

            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('position')->nullable();

            // Decision-maker type (spec 00 — ContactRoleType); free text is never stored.
            $table->string('role_type');

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();

            // Exactly one primary contact per company — enforced in the controller (spec 07 §3)
            $table->boolean('is_primary')->default(false);

            // active / inactive — inactive hides a contact from pickers, never deletes it
            $table->string('status')->default('active');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_primary']);
            // Email is unique per company (advisory — spec 07 §4), so lookups go company-first.
            $table->index(['company_id', 'email']);
            $table->index('role_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
