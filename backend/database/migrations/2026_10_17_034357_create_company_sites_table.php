<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sites', function (Blueprint $table): void {
            // ULID primary key — route key (ADR-0007)
            $table->ulid('id')->primary();

            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('label');

            // PH address format (spec 00 §CompanySite)
            $table->string('street_address')->nullable();
            $table->string('barangay')->nullable();
            $table->string('city_municipality')->nullable();
            $table->string('province')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();

            // One primary site per company — enforced in the controller (spec 06 §acceptance)
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sites');
    }
};
