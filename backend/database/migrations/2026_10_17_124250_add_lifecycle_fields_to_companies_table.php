<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Churn (spec 09 §5): why and when a client left.
            $table->string('churn_reason')->nullable();
            $table->timestamp('churned_at')->nullable();

            // Mocked Service handoff (spec 09 §2, ADR-0005): the outcome of the last
            // request, so a failure can be retried instead of silently lost.
            $table->string('handoff_status')->nullable();
            $table->string('handoff_reference')->nullable();
            $table->text('handoff_error')->nullable();
            $table->timestamp('handoff_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'churn_reason',
                'churned_at',
                'handoff_status',
                'handoff_reference',
                'handoff_error',
                'handoff_requested_at',
            ]);
        });
    }
};
