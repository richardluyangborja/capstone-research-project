<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 08 slice S2 (spec 19, ADR-0030): the immutable audit trail. Rows are append-only
     * at the application layer — there is no update or delete route, and a correction is a new
     * event. Snapshots are redacted by the writer before persistence (no passwords, tokens,
     * hashes or private paths).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('actor_id')->nullable()->index(); // nullable for system events
            $table->string('action', 64)->index();
            $table->string('severity', 20)->default('info')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('request_path')->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->string('request_user_agent', 255)->nullable();
            $table->string('request_id', 64)->index();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
