<?php

namespace App\Models;

use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable satisfaction survey (module 05 slice S1, spec 13, ADR-0019 §1).
 *
 * The library holds the wording and the ordered question set; slice S2 sends it to a client as an
 * invitation and slice S4 answers the survey. Because invitations and responses point back here,
 * the structure is frozen once one has gone out (`SurveyRequest` guards it) and `duplicate` is the
 * way to version a survey — exactly the stance message templates take (ADR-0017 §10).
 */
class Survey extends Model
{
    /** @use HasFactory<SurveyFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'created_by',
    ];

    /**
     * A new survey is a draft: the store request lets `is_active` be omitted, and the response
     * should still report the default rather than `null`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The questions in the order they are asked (spec 13 §Data model).
     */
    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The sends (slice S2) and what came back (slice S3).
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    /**
     * Whether this survey has ever gone out: from then on its question set is frozen, and
     * `duplicate` is the way to change what is asked (spec 13 §Business rules 3, ADR-0019 §1).
     */
    public function hasBeenSent(): bool
    {
        return $this->invitations()->exists();
    }

    /**
     * Who authored it — display only. A survey outlives its author.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The surveys slice S2 may send: active ones. A draft stays visible in the library so a
     * manager can finish writing it.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
        });
    }

    /**
     * Whether a live survey already answers to this name, case-insensitively — the live-unique
     * rule (spec 13 §Business rules 11), shared by the request and `restore`.
     */
    public static function nameTaken(string $name, ?string $exceptId = null): bool
    {
        return self::query()
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->exists();
    }

    /**
     * A free name to duplicate under: "Name (copy)", then "(copy 2)", and so on — the name is a
     * picker label, so a copy never shadows the survey it came from.
     */
    public function copyName(): string
    {
        $base = $this->name;
        $candidate = $base.' (copy)';
        $suffix = 2;

        while (self::nameTaken($candidate)) {
            $candidate = sprintf('%s (copy %d)', $base, $suffix);
            $suffix++;
        }

        return $candidate;
    }
}
