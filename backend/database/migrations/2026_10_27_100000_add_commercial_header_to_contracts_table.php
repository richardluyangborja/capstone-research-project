<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The contract commercial header (module 01 refresh S6, spec 16 §Contract commercial model,
     * ADR-0024). `value` stays the negotiated headline and is never derived from the header.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('payment_terms')->nullable()->after('currency');
            $table->string('po_reference')->nullable()->after('payment_terms');
            $table->boolean('auto_renew')->default(false)->after('po_reference');
            $table->unsignedSmallInteger('notice_days')->nullable()->after('auto_renew');
            $table->string('signatory_name')->nullable()->after('notice_days');
            $table->string('signatory_position')->nullable()->after('signatory_name');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_terms',
                'po_reference',
                'auto_renew',
                'notice_days',
                'signatory_name',
                'signatory_position',
            ]);
        });
    }
};
