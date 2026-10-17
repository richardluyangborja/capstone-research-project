<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table): void {
            // ULID primary key — route key; never an auto-incrementing id (ADR-0007)
            $table->ulid('id')->primary();
            $table->string('reference')->unique();

            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Origin lead (submodule 08). Plain ULID: the lead is kept for history and is
            // never the reason a deal disappears.
            $table->ulid('lead_id')->nullable();

            $table->string('name');

            // Deal pipeline (ADR-0002)
            $table->string('stage')->default('new_inquiry');

            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency')->default('PHP');
            $table->unsignedTinyInteger('probability')->nullable();
            $table->date('expected_close_date')->nullable();
            $table->date('actual_close_date')->nullable();
            $table->string('source')->nullable();

            $table->ulid('primary_contact_id')->nullable();

            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('lost_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('stage');
            $table->index('company_id');
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
