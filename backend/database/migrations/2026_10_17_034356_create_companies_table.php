<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            // ULID primary key — route key; never an auto-incrementing id (ADR-0007)
            $table->ulid('id')->primary();
            $table->string('reference')->unique();

            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();

            // Branches: a company may sit under a parent. Plain ULID (no FK constraint:
            // SQLite cannot ALTER-add one and tests run on SQLite); cycles are rejected
            // at the application layer (spec 06 §3).
            $table->ulid('parent_company_id')->nullable()->index();

            $table->string('status')->default('prospect');
            $table->string('tier')->nullable();
            $table->date('client_since')->nullable();

            // Origin lead when the company came from conversion (submodule 08).
            $table->ulid('source_lead_id')->nullable()->index();

            $table->json('tags')->nullable();

            // Owner is required (spec 06 §7); restrict rather than cascade so deleting a
            // user cannot silently take their accounts with them.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
