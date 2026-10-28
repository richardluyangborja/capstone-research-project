<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured commercial lines behind a contract (module 01 refresh S7, spec 16
     * §Contract commercial model, ADR-0024). The set is replaced wholesale; the totals are
     * computed on read and never stored.
     */
    public function up(): void
    {
        Schema::create('contract_rate_cards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('company_sites')->nullOnDelete();

            $table->string('role_title');
            $table->unsignedInteger('headcount');
            $table->string('billing_unit');
            $table->decimal('bill_rate', 15, 2);
            $table->decimal('pay_rate', 15, 2)->nullable();
            $table->decimal('units_per_period', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position');

            $table->timestamps();
            $table->softDeletes();

            $table->index('contract_id');
            $table->index('billing_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_rate_cards');
    }
};
