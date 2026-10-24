<?php

namespace App\Http\Dashboard;

use App\Enums\Role;
use App\Models\User;

/**
 * What the caller may see (spec 14 §Roles, ADR-0004): salesrep → personal, manager → team,
 * admin/superadmin → org.
 *
 * The tier is resolved on the server and every list is scoped with the module's own
 * `visibleTo`, so the client's role prop is presentation only. `allows()` is the same
 * personal < team < org gate the D1 client applies to alerts and worklist rows.
 */
final readonly class DashboardScope
{
    /** @var array<string, int> */
    private const RANKS = ['personal' => 0, 'team' => 1, 'org' => 2];

    public function __construct(
        public string $tier,
        public string $label,
        public string $description,
    ) {}

    public static function for(User $user): self
    {
        if ($user->role->isAdmin()) {
            return new self(
                'org',
                'Primepower · CRM org-wide',
                'Every team, every account, every answer.',
            );
        }

        if ($user->role === Role::Manager) {
            $team = $user->team?->name;

            return new self(
                'team',
                $team !== null ? $team.' team' : 'Your team',
                $team !== null
                    ? $team.' · the team\'s accounts, deals and answers.'
                    : 'Your team\'s accounts, deals and answers.',
            );
        }

        return new self(
            'personal',
            'Your book',
            'Leads, accounts, deals and answers you own.',
        );
    }

    public function allows(string $minTier): bool
    {
        return self::RANKS[$this->tier] >= (self::RANKS[$minTier] ?? 0);
    }

    /**
     * @return array{role: string, tier: string, label: string, description: string}
     */
    public function toArray(User $user): array
    {
        return [
            'role' => $user->role->value,
            'tier' => $this->tier,
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
