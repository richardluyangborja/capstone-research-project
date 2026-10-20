<?php

namespace App\Models;

use App\Enums\SurveyBand;
use App\Enums\SurveyInvitationStatus;
use App\Enums\SurveyQuestionType;
use App\Events\SurveyResponseSubmitted;
use Carbon\CarbonInterface;
use Database\Factories\SurveyResponseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a client answered (module 05 slice S2, spec 13, ADR-0019 §6).
 *
 * A response is written once, by the public form, through `record()` — the single place the
 * score is computed. `csat` is the mean of the rating answers, `nps` is the promoter answer and
 * `band` is what that answer means; storing them lets the later books filter in SQL while the
 * aggregates themselves stay derived on read (slice S3).
 *
 * `needs_attention` is stamped here too: the closed loop that acts on it is slice S4, but the
 * rule that decides it belongs with the scoring that knows the numbers.
 */
class SurveyResponse extends Model
{
    /** @use HasFactory<SurveyResponseFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'invitation_id',
        'survey_id',
        'company_id',
        'contact_id',
        'submitted_at',
        'csat',
        'nps',
        'band',
        'needs_attention',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'csat' => 'float',
            'nps' => 'integer',
            'band' => SurveyBand::class,
            'needs_attention' => 'boolean',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class);
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The answers in the order the survey asked them.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class, 'response_id');
    }

    /**
     * The same answers ordered by their question's position — the order the client saw. The
     * relation itself cannot order by a column on another table, so the read sorts once the
     * questions are loaded (slice S3's detail).
     *
     * @return Collection<int, SurveyAnswer>
     */
    public function answersInOrder(): Collection
    {
        return $this->answers
            ->sortBy(fn (SurveyAnswer $answer): int => (int) ($answer->question?->position ?? 0))
            ->values();
    }

    /**
     * Store one submission: the response, its answers, the score, and the invitation's death.
     *
     * This is the only writer — the public endpoint and the demo seeder both come through here,
     * so a seeded response is scored exactly like a real one.
     *
     * @param  array<int, array{question_id: string, value_number?: int|null, value_text?: string|null, value_options?: array<int, string>|null}>  $answers
     */
    public static function record(
        SurveyInvitation $invitation,
        array $answers,
        ?CarbonInterface $submittedAt = null,
    ): self {
        return DB::transaction(function () use ($invitation, $answers, $submittedAt): self {
            /** @var SurveyResponse $response */
            $response = $invitation->response()->create([
                'survey_id' => $invitation->survey_id,
                'company_id' => $invitation->company_id,
                'contact_id' => $invitation->contact_id,
                'submitted_at' => $submittedAt ?? now(),
            ]);

            foreach ($answers as $answer) {
                $response->answers()->create([
                    'question_id' => $answer['question_id'],
                    'value_number' => $answer['value_number'] ?? null,
                    'value_text' => $answer['value_text'] ?? null,
                    'value_options' => $answer['value_options'] ?? null,
                ]);
            }

            $response->score();

            $invitation->forceFill([
                'status' => SurveyInvitationStatus::Submitted,
                'submitted_at' => $response->submitted_at,
            ])->save();

            SurveyResponseSubmitted::dispatch($response);

            return $response;
        });
    }

    /**
     * Derive the score from the answers: CSAT is the mean rating (two decimals, null when the
     * survey asks none), NPS is the promoter answer, and the band is that answer's meaning.
     * Attention is at or below either configured bound (ADR-0019 §7) — the trigger slice S4
     * turns into a call-back.
     */
    public function score(): void
    {
        $this->loadMissing('answers.question');

        $ratings = $this->answers
            ->filter(fn (SurveyAnswer $answer): bool => $answer->question?->type === SurveyQuestionType::Rating)
            ->pluck('value_number')
            ->filter(fn (?int $value): bool => $value !== null);

        $promoter = $this->answers
            ->first(fn (SurveyAnswer $answer): bool => $answer->question?->type === SurveyQuestionType::Nps);

        $this->csat = $ratings->isEmpty() ? null : round((float) $ratings->avg(), 2);
        $this->nps = $promoter?->value_number;
        $this->band = SurveyBand::fromNps($this->nps);
        $this->needs_attention = ($this->csat !== null && $this->csat <= (float) config('crm.surveys.attention_csat_max'))
            || ($this->nps !== null && $this->nps <= (int) config('crm.surveys.attention_nps_max'));

        $this->save();
    }

    /**
     * The satisfaction numbers every view shares (spec 13 §Business rules 9): CSAT is the mean
     * of the stored per-response scores, NPS is the promoter share minus the detractor share over
     * the responses that carried a promoter answer, and the bands are plain counts.
     *
     * `response_rate` needs the invitation count, which lives in another table, so the caller
     * pairs it in (see `SatisfactionController`).
     *
     * @return array{responses: int, csat: float|null, nps: int|null, promoters: int, passives: int, detractors: int, answered: int, attention: int}
     */
    public static function metrics(Builder $query): array
    {
        $totals = (clone $query)
            ->reorder()
            ->selectRaw('count(*) as responses')
            ->selectRaw('avg(csat) as csat')
            ->selectRaw('sum(case when band = ? then 1 else 0 end) as promoters', [SurveyBand::Promoter->value])
            ->selectRaw('sum(case when band = ? then 1 else 0 end) as passives', [SurveyBand::Passive->value])
            ->selectRaw('sum(case when band = ? then 1 else 0 end) as detractors', [SurveyBand::Detractor->value])
            ->selectRaw('sum(case when needs_attention then 1 else 0 end) as attention')
            ->first();

        $promoters = (int) ($totals->promoters ?? 0);
        $passives = (int) ($totals->passives ?? 0);
        $detractors = (int) ($totals->detractors ?? 0);
        $answered = $promoters + $passives + $detractors;

        return [
            'responses' => (int) ($totals->responses ?? 0),
            'csat' => $totals->csat === null ? null : round((float) $totals->csat, 2),
            'nps' => $answered === 0 ? null : (int) round((($promoters - $detractors) / $answered) * 100),
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'answered' => $answered,
            'attention' => (int) ($totals->attention ?? 0),
        ];
    }

    /**
     * A response is visible exactly when its account is (spec 13 §Business rules 10, ADR-0004).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->isAdmin()) {
            return $query;
        }

        return $query->whereIn('company_id', Company::query()->visibleTo($user)->select('id'));
    }

    /**
     * The response book's band filter. A response with no NPS answer has no band and is found by
     * no band value — it still counts for CSAT (spec 13 §Business rules 9).
     */
    public function scopeFilterBand(Builder $query, ?string $band): Builder
    {
        if (! $band) {
            return $query;
        }

        return $query->where('band', $band);
    }

    /**
     * `filter[attention]=true|false` — the "who is unhappy" view and its complement.
     */
    public function scopeFilterAttention(Builder $query, ?string $attention): Builder
    {
        return match ($attention) {
            'true', '1' => $query->where('needs_attention', true),
            'false', '0' => $query->where('needs_attention', false),
            default => $query,
        };
    }

    /**
     * A submitted-at window, inclusive of both business days (the caller's dates are Asia/Manila).
     */
    public function scopeFilterDates(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query, string $date) => $query->where('submitted_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($to, fn (Builder $query, string $date) => $query->where('submitted_at', '<=', Carbon::parse($date)->endOfDay()));
    }

    /**
     * Search across the account, the person, the survey and the invitation reference.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->whereHas('company', fn (Builder $company) => $company->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orWhereHas('contact', fn (Builder $contact) => $contact->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orWhereHas('survey', fn (Builder $survey) => $survey->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orWhereHas('invitation', fn (Builder $invitation) => $invitation->whereRaw('LOWER(reference) LIKE ?', [$like]));
        });
    }
}
