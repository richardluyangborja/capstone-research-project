<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            // ULID primary key — route key (ADR-0007)
            $table->ulid('id')->primary();
            $table->string('type');
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            // Polymorphic target: lead / company / contact / opportunity / contract (all ULIDs)
            $table->nullableUlidMorphs('subject');

            $table->timestamps();

            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
