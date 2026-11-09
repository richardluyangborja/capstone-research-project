<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 08 slice S4 (spec 19, ADR-0030): one row per overridden allow-listed setting key.
 *
 * Only overrides live here — a key with no row reads its default (or environment) value from
 * `config/`, so `source` (`database`/`environment`/`default`) is derivable on read. Values are
 * json so the typed registry can store bools, numbers, decimals and strings without a second
 * column per type. Single-tenant phase (spec §6), so there is no organization column; scope
 * stays a registry property. `user.*` keys arrive with S9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
