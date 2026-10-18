<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The polymorphic attachments table that `00-data-model.md` reserved for records that
     * need several files (module 03 slice S3). Phase 1 kept the contract document on the
     * contract row; this is the general table, and communications are its first user.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            // ULID primary key — route key (ADR-0007)
            $table->ulid('id')->primary();

            // The record the file hangs off (a communication today; ULID keys throughout).
            $table->ulidMorphs('attachable');

            // Where the file actually lives. The path is never exposed through the API; the
            // file is streamed from an authorized endpoint (spec 11 §S3).
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('size');

            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
