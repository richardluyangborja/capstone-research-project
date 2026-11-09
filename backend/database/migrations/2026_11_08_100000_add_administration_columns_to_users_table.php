<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Module 08 slice S2 (spec 19, ADR-0030): the user account lifecycle columns.
     *
     * `public_id` is the admin URL key (ULID, ADR-0007) while the bigint `users.id` stays the
     * internal JWT/FK key and is never URL-exposed. `status` is `active`/`suspended` — accounts
     * are suspended and restored, never deleted.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('public_id')->unique()->after('id');
            $table->string('status', 20)->default('active')->after('password');
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete()->after('suspended_at');
            $table->string('status_reason')->nullable()->after('suspended_by');
        });

        // Backfill deterministic ULIDs for rows that pre-date the column (fresh installs have
        // none, but an upgraded database must not keep empty admin URL keys).
        foreach (User::query()->whereNull('public_id')->cursor() as $user) {
            $user->forceFill(['public_id' => (string) Str::ulid()])->saveQuietly();
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['public_id', 'status', 'suspended_at', 'suspended_by', 'status_reason']);
        });
    }
};
