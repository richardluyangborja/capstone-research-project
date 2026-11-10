<?php

namespace App\Http\Communications;

use App\Enums\CommunicationStatus;
use App\Integrations\EmailGateway;
use App\Models\Communication;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The one place that hands a communication to the email gateway (module 03 slice S2).
 *
 * The history book's send/retry path and the administration's allow-listed retry action share
 * these state rules, so the two surfaces cannot drift: only a message in one of the permitted
 * states may leave, it must be sendable (an outbound email), and it needs a recipient. A caller
 * still authorizes the action first — sending needs the subject's update right, the admin retry
 * needs `CommunicationPolicy::retry`.
 */
final class CommunicationDelivery
{
    /**
     * @param  array<int, CommunicationStatus>  $from
     *
     * @throws ValidationException when the message cannot leave in its current state or has no recipient
     */
    public function handToGateway(
        EmailGateway $gateway,
        Communication $communication,
        User $actor,
        array $from,
        string $expected,
    ): Communication {
        if (! in_array($communication->status, $from, true) || ! $communication->isSendable()) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Only %s can go to the mail gateway.', $expected)],
            ]);
        }

        // A rep may pick a person instead of typing an address; a send needs a destination.
        $communication->fillRecipientFromContact();

        if (($communication->to_address ?? '') === '') {
            throw ValidationException::withMessages([
                'to_address' => ['Add a recipient before sending — pick a contact or type an address.'],
            ]);
        }

        return $communication->sendThrough($gateway, $actor);
    }
}
