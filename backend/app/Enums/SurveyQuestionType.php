<?php

namespace App\Enums;

/**
 * The five ways a survey asks a question (module 05 slice S1, spec 13 §Data model).
 *
 * Each type has its own answer column on `survey_answers` (ADR-0019 §2): a `rating` and an `nps`
 * answer are numbers, the two choice types are option lists, and `text` is free text. Keeping the
 * value typed is what lets the database validate ranges and aggregate scores instead of trusting
 * a blob.
 */
enum SurveyQuestionType: string
{
    case Rating = 'rating';
    case Nps = 'nps';
    case SingleChoice = 'single_choice';
    case MultiChoice = 'multi_choice';
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Rating => 'Rating (1–5)',
            self::Nps => 'Net promoter (0–10)',
            self::SingleChoice => 'Single choice',
            self::MultiChoice => 'Multiple choice',
            self::Text => 'Free text',
        };
    }

    /**
     * The two types that need an options list, and the three that must not carry one.
     */
    public function hasOptions(): bool
    {
        return $this === self::SingleChoice || $this === self::MultiChoice;
    }

    /**
     * Whether the answer feeds a score — CSAT from `rating`, NPS from `nps`.
     */
    public function isScored(): bool
    {
        return $this === self::Rating || $this === self::Nps;
    }
}
