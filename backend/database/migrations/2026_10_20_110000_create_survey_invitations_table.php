<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A survey sent to a client (module 05 slice S2, spec 13, ADR-0019 §3–5).
     *
     * The invitation is the unit that goes out: it snapshots the recipient, carries the
     * single-use token the public page is keyed by, and points at the `Communication` that
     * logged the email (module 03), so the account history tells the same story.
     *
     * `status` stores **sent/failed/submitted/cancelled** only. `expired` is derived from
     * `expires_at` on read, so nothing has to sweep the table.
     */
    public function up(): void
    {
        Schema::create('survey_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('reference')->unique();
            $table->foreignUlid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Who it went to, and what prompted it. Both are context, not ownership.
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('contract_id')->nullable()->constrained('contracts')->nullOnDelete();

            // The logged email. Deleting the message leaves the send history intact.
            $table->foreignUlid('communication_id')->nullable()->constrained('communications')->nullOnDelete();

            // 48 random characters, never the ULID: the link is the credential (ADR-0019 §3).
            $table->string('token', 64)->unique();

            // A snapshot: the contact can be renamed or retired and the record still says who
            // received it.
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email');
            $table->text('message')->nullable();

            $table->string('status')->default('sent');
            $table->string('failed_reason')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('company_id');
            $table->index('survey_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_invitations');
    }
};
