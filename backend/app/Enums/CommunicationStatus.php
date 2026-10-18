<?php

namespace App\Enums;

/**
 * Delivery state of a communication (spec 11 §Channels, ADR-0017 §3).
 *
 * `scheduled` is reserved for the follow-up module (module 04) and cannot be set here.
 * `sent`/`received`/`failed` are gateway outcomes; `logged` is a touch recorded after the
 * fact (a call taken in person); `draft` is an outbound email waiting to be sent (slice S2).
 * Only `draft`/`logged` records are editable (ADR-0017 §6).
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
     * `scheduled` belongs to module 04.
     *
     * @return array<int, self>
     */
    public static function loggable(): array
    {
        return [self::Sent, self::Received, self::Failed, self::Logged];
    }

    /**
     * The statuses a caller may submit for a message on this channel and direction (slice S2):
     * everything that already happened, plus `draft` for an outbound email — only electronic
     * mail can be written and sent later, so only it has a draft.
     *
     * @return array<int, self>
     */
    public static function settableFor(CommunicationChannel $channel, CommunicationDirection $direction): array
    {
        $statuses = self::loggable();

        if ($channel === CommunicationChannel::Email && $direction === CommunicationDirection::Outbound) {
            $statuses[] = self::Draft;
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
     * A delivered or received message is a record of what was said, not a draft.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Logged;
    }
}
