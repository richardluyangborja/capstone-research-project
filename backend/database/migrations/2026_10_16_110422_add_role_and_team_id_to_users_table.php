<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('salesrep')->after('email');
            // Plain indexed ULID (no FK constraint: SQLite cannot ALTER-add one, and
            // tests run on SQLite — integrity is enforced at the app layer).
            $table->ulid('team_id')->nullable()->after('role')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'role']);
        });
    }
};
