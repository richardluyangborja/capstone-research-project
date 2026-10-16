<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            // ULID primary key — route key; never an auto-incrementing id (ADR-0007)
            $table->ulid('id')->primary();
            $table->string('reference')->unique();

            $table->string('company_name');
            $table->string('contact_name');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_position')->nullable();

            $table->string('source');
            $table->string('source_detail')->nullable();
            $table->string('industry')->nullable();
            $table->string('location')->nullable();
            $table->string('service_interest')->nullable();

            // Qualification checklist (submodule 2)
            $table->unsignedInteger('estimated_headcount')->nullable();
            $table->text('needed_roles')->nullable();
            $table->date('target_start_date')->nullable();
            $table->decimal('budget_rate', 15, 2)->nullable();
            $table->boolean('authority_confirmed')->default(false);
            $table->string('work_site')->nullable();
            $table->string('urgency')->nullable();

            $table->string('rating')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->text('qualification_notes')->nullable();
            $table->string('disqualification_reason')->nullable();
            $table->text('notes')->nullable();

            $table->string('status')->default('new');
            $table->timestamp('status_changed_at')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->ulid('converted_company_id')->nullable();
            $table->ulid('converted_opportunity_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
