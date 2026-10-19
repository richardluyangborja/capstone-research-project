<?php

namespace App\Enums;

/**
 * Delivery state of a communication (spec 11 §Channels, ADR-0017 §3).
 *
 * `sent`/`received`/`failed` are gateway outcomes; `logged` is a touch recorded after the
 * fact (a call taken in person); `draft` is an outbound email waiting to be sent (slice S2)
 * and `scheduled` is one waiting for a due time (module 04 slice S2, spec 12). A `draft`,
 * a `scheduled` message and a `logged` one — none of which is a record of what was said —
 * are the only editable states (ADR-0017 §6).
 */
enum CommunicationStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Received = 'received';
    case Failed = 'failed';
    case Logged = 'logged';

    /**
     * Statuses a caller may submit when logging something that already happened.
     *
     * @return array<int, self>
     */
    public static function loggable(): array
    {
        return [self::Sent, self::Received, self::Failed, self::Logged];
    }

    /**
     * The statuses a caller may submit for a message on this channel and direction:
     * everything that already happened, plus `draft` and `scheduled` for an outbound email —
     * only electronic mail can be written now and sent later, so only it can be put off.
     *
     * @return array<int, self>
     */
    public static function settableFor(CommunicationChannel $channel, CommunicationDirection $direction): array
    {
        $statuses = self::loggable();

        if ($channel === CommunicationChannel::Email && $direction === CommunicationDirection::Outbound) {
            $statuses[] = self::Draft;
            $statuses[] = self::Scheduled;
        }

        return $statuses;
    }

    /**
     * The status a newly logged message takes when the caller does not pick one:
     * anything inbound has been received; an electronic outbound message has been sent;
     * everything else is simply logged.
     */
    public static function defaultFor(CommunicationChannel $channel, CommunicationDirection $direction): self
    {
        if ($direction === CommunicationDirection::Inbound) {
            return self::Received;
        }

        return $channel->isElectronic() ? self::Sent : self::Logged;
    }

    /**
     * A message that is still the writer's to change: a draft, a scheduled one (nobody has read
     * it yet) or a touch logged after the fact. A delivered or received message is a record of
     * what was said, not a draft field (ADR-0017 §6, spec 12 §4).
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Logged], true);
    }

    /**
     * The word the history book and the worklist show for this state.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Sent => 'Sent',
            self::Received => 'Received',
            self::Failed => 'Failed',
            self::Logged => 'Logged',
        };
    }
}
