<?php

namespace App\Models;

use App\Enums\SurveyBand;
use App\Enums\SurveyInvitationStatus;
use App\Enums\SurveyQuestionType;
use App\Events\SurveyResponseSubmitted;
use Carbon\CarbonInterface;
use Database\Factories\SurveyResponseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
