<?php

namespace App\Models;

use Database\Factories\CompanySiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company location in PH address format (spec 00 §CompanySite). One site per company
 * is flagged `is_primary`.
 */
class CompanySite extends Model
{
    /** @use HasFactory<CompanySiteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'label',
        'street_address',
        'barangay',
        'city_municipality',
        'province',
        'region',
        'postal_code',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Address parts joined for display; empty parts are dropped.
     */
    public function fullAddress(): string
    {
        return collect([
            $this->street_address,
            $this->barangay,
            $this->city_municipality,
            $this->province,
            $this->region,
            $this->postal_code,
        ])->filter(fn (?string $part): bool => filled($part))->implode(', ');
    }
}
