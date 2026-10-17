<?php

namespace App\Enums;

/**
 * The deal pipeline (ADR-0002) — separate from lead qualification so unqualified names
 * never distort forecasting.
 */
enum OpportunityStage: string
{
    case NewInquiry = 'new_inquiry';
    case Qualified = 'qualified';
    case Discovery = 'discovery';
    case ProposalSent = 'proposal_sent';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
    case OnHold = 'on_hold';

    /**
     * Win probability a deal starts with at each stage (spec 00 — `probability` defaults
     * from the stage and is overridable).
     */
    public function defaultProbability(): int
    {
        return match ($this) {
            self::NewInquiry => 10,
            self::Qualified => 20,
            self::Discovery => 40,
            self::ProposalSent => 60,
            self::Negotiation => 80,
            self::Won => 100,
            self::Lost => 0,
            self::OnHold => 20,
        };
    }

    /**
     * Terminal stages close the deal; everything else is still forecastable.
     */
    public function isTerminal(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }
}
