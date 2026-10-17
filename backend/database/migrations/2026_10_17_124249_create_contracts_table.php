<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            // ULID primary key — route key; never an auto-incrementing id (ADR-0007)
            $table->ulid('id')->primary();
            $table->string('reference')->unique();

            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // The deal a contract closes (submodule 08). Plain ULID: a contract outlives the
            // deal record it came from and is never removed with it.
            $table->ulid('opportunity_id')->nullable();

            // Renewal chain (spec 09 §6): the contract this one replaces.
            $table->ulid('renewal_of_contract_id')->nullable();

            $table->string('title');

            // draft → sent → signed → expired|terminated (spec 09 §1)
            $table->string('status')->default('draft');

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->decimal('value', 15, 2)->nullable();
            $table->string('currency')->default('PHP');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();

            // Stored document (private disk; served through an authorized endpoint).
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('file_mime')->nullable();

            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('company_id');
            $table->index('opportunity_id');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
