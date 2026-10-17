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

    /**
     * Legal target stages (module 02, ADR-0015 §1). The pipeline is **any-to-any**: a deal
     * may move to any other stage, but a terminal stage may only be **reopened to an open
     * stage** — never terminal→terminal.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        if ($this->isTerminal()) {
            return array_values(array_filter(self::cases(), fn (self $stage): bool => $stage->isOpen()));
        }

        return array_values(array_filter(self::cases(), fn (self $stage): bool => $stage !== $this));
    }

    public function canTransitionTo(self $stage): bool
    {
        return in_array($stage, $this->allowedTransitions(), true);
    }

    /**
     * The open stages in pipeline order — the board columns (module 02 §UI).
     *
     * @return array<int, self>
     */
    public static function openStages(): array
    {
        return [self::NewInquiry, self::Qualified, self::Discovery, self::ProposalSent, self::Negotiation, self::OnHold];
    }
}
