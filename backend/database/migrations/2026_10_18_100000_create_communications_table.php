<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The detailed communication history (module 03, spec 11, ADR-0017). `Activity` stays
     * the lightweight quick log; this is the channel-aware record.
     */
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table): void {
            // ULID primary key — route key (ADR-0007)
            $table->ulid('id')->primary();
            $table->string('reference')->unique();

            // Subject: lead / company / contact / opportunity / contract (all ULIDs).
            // Required — no orphan messages (ADR-0017 §5).
            $table->ulidMorphs('subject');

            // The person on the other end, when known.
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('channel');
            $table->string('direction');
            $table->string('status');

            // Title line: an email subject, or a one-line summary of a call.
            $table->string('subject');
            $table->text('body')->nullable();

            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();
            $table->string('to_name')->nullable();
            $table->string('to_address')->nullable();

            $table->timestamp('occurred_at');

            // Calls/meetings.
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('outcome')->nullable();

            // Message id from the mocked email gateway (slice S2).
            $table->string('external_id')->nullable();
            $table->string('failed_reason')->nullable();

            // Threading (slice S4) — columns land now so the model does not change later.
            $table->string('thread_id')->nullable();
            $table->ulid('in_reply_to_id')->nullable();

            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('channel');
            $table->index('direction');
            $table->index('status');
            $table->index('occurred_at');
            $table->index('thread_id');
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
