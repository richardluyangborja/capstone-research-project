<?php

namespace App\Models;

use App\Enums\SurveyQuestionType;
use Database\Factories\SurveyQuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question in a survey, in the order it is asked (module 05 slice S1, spec 13).
 *
 * The type decides which answer column slice S2 fills: `rating` (1–5) and `nps` (0–10) are
 * numbers, the two choice types are option lists, `text` is free text (ADR-0019 §2). A choice
 * question must carry 2–10 options; the request enforces it because the column cannot.
 */
class SurveyQuestion extends Model
{
    /** @use HasFactory<SurveyQuestionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'survey_id',
        'type',
        'prompt',
        'help',
        'required',
        'position',
        'options',
    ];

    /**
     * The column default, applied to a fresh model so the resource reports it consistently.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'required' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SurveyQuestionType::class,
            'required' => 'boolean',
            'position' => 'integer',
            'options' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
