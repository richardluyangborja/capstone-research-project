<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->string('scope')->default('system')->after('id');
            $table->unsignedBigInteger('scope_id')->default(0)->after('scope');
            $table->unique(['scope', 'scope_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique(['scope', 'scope_id', 'key']);
            $table->dropColumn('scope_id');
            $table->dropColumn('scope');
            $table->unique('key');
        });
    }
};
