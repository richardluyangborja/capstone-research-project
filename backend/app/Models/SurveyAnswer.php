<?php

namespace App\Models;

use Database\Factories\SurveyAnswerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer inside a response (module 05 slice S2, spec 13, ADR-0019 §2).
 *
 * The populated value column is decided by the question's type — a number for `rating` and
 * `nps`, the chosen options for the choice types, free text for `text` — which is what lets the
 * score be computed and the public form's ranges be enforced.
 */
class SurveyAnswer extends Model
{
    /** @use HasFactory<SurveyAnswerFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'response_id',
        'question_id',
        'value_number',
        'value_text',
        'value_options',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'integer',
            'value_options' => 'array',
        ];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class);
    }
}
