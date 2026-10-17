<?php

namespace App\Models;

use App\Enums\ContactRoleType;
use App\Enums\ContactStatus;
use App\Enums\Role;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A person at a company (spec 07). Contacts have no owner of their own — permissions and
 * scoping are inherited from the owning company (ADR-0004) — and they are never hard deleted
 * (spec 07 §6: `status = inactive` hides one from pickers, `deleted_at` retires it).
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'position',
        'role_type',
        'email',
        'phone',
        'mobile',
        'is_primary',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_type' => ContactRoleType::class,
            'status' => ContactStatus::class,
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact): void {
            // Keep the in-memory default in step with the column default (spec 00 — active).
            $contact->status ??= ContactStatus::Active;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Contacts inherit their company's visibility (ADR-0004): salesrep own, manager team,
     * admin all. There is no shared queue for contacts.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->isAdmin()) {
            return $query;
        }

        if ($user->role === Role::Manager && $user->team_id !== null) {
            return $query->whereHas('company', fn (Builder $company) => $company->visibleTo($user));
        }

        return $query->whereHas('company', fn (Builder $company) => $company->where('owner_id', $user->id));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                ->orWhereRaw('LOWER(mobile) LIKE ?', [$like])
                ->orWhereRaw('LOWER(position) LIKE ?', [$like]);
        });
    }

    public function isActive(): bool
    {
        return $this->status === ContactStatus::Active;
    }

    /**
     * Make this the company's primary contact, clearing the previous one in the same
     * transaction (spec 07 §3 — exactly one primary per company).
     */
    public function promoteToPrimary(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('company_id', $this->company_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_primary' => false]);

            $this->forceFill(['is_primary' => true])->save();
        });
    }

    /**
     * Re-assert "exactly one primary per company" after the primary stepped aside (deleted,
     * deactivated) or a contact came back: the current primary keeps the flag, otherwise the
     * oldest active contact takes over. Returns the resulting primary, if any.
     */
    public static function syncPrimaryFor(string $companyId): ?self
    {
        $primary = static::query()
            ->where('company_id', $companyId)
            ->where('status', ContactStatus::Active)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->first();

        static::query()->where('company_id', $companyId)->update(['is_primary' => false]);

        if ($primary === null) {
            return null;
        }

        $primary->forceFill(['is_primary' => true])->save();

        return $primary;
    }

    /**
     * Advisory check for the per-company email rule (spec 07 §4): the same email may exist on
     * another company, but on this one it is worth warning about. Never blocks a save.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function emailDuplicates(string $companyId, ?string $email, ?string $exceptId = null): array
    {
        if (blank($email)) {
            return [];
        }

        return static::query()
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->limit(5)
            ->get(['id', 'name', 'email', 'role_type', 'status'])
            ->map(fn (self $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'role_type' => $contact->role_type,
                'status' => $contact->status,
            ])
            ->all();
    }
}
