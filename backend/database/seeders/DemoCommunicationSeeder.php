<?php

namespace Database\Seeders;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use App\Events\CommunicationLogged;
use App\Integrations\MockEmailGateway;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Fixed demo communication history (no faker — seeded for the proposal demo).
 *
 * Runs after the lead/company/contact/opportunity/contract seeders because every message
 * hangs off one of those records. Logging dispatches `CommunicationLogged`, so the timeline
 * mirrors land too (module 03, ADR-0017 §2) — the lead and deal timestamps stay consistent
 * with the touch dates the pipeline seeder set.
 *
 * Greenfield Hotels is deliberately silent so the stale/at-risk worklists have a subject.
 */
class DemoCommunicationSeeder extends Seeder
{
    /**
     * A valid 1x1 PNG standing in for a scanned page — the same placeholder the contract
     * seeder uses. It is a real file, so a demo download actually opens (slice S3).
     */
    private const PLACEHOLDER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function run(): void
    {
        $sam = User::where('email', 'salesrep@primepower.test')->firstOrFail();
        $liza = User::where('email', 'salesrep2@primepower.test')->firstOrFail();

        // --- Sunrise Foods Inc: an inbound enquiry, a proposal out, and the follow-up call.
        $sunrise = $this->company('Sunrise Foods Inc');

        if ($sunrise !== null) {
            $contact = $this->primaryContact($sunrise);

            $this->log($sunrise->sourceLead, $sam, [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Inbound,
                'contact_id' => $contact?->id,
                'subject' => 'Website enquiry — warehouse staffing for the holiday peak',
                'body' => "Hi,\n\nWe found you through the staffing directory. We need around 12 packers and 3 forklift operators for the holiday peak at our Balintawak commissary. Could you send your rate card and lead times?\n\nThanks,\nRosa",
                'from_name' => $contact?->name,
                'from_address' => $contact?->email,
                'to_name' => 'Primepower Sales',
                'occurred_at' => Carbon::now()->subDays(10),
            ]);

            $rateSheet = $this->log($this->deal('Sunrise Foods Inc — warehouse staffing'), $sam, [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Outbound,
                'contact_id' => $contact?->id,
                'subject' => 'Revised rate sheet and crew schedule',
                'body' => "Hi Rosa,\n\nAttached is the revised rate sheet for the warehouse crew, plus the proposed two-shift schedule. Rates hold for 30 days.\n\nBest,\nSam",
                'from_name' => $sam->name,
                'from_address' => 'sales@primepower.test',
                'to_name' => $contact?->name,
                'to_address' => $contact?->email,
                'occurred_at' => Carbon::now()->subDays(2),
            ]);

            // Sent mail keeps what it carried, so this message shows the locked side of S3.
            $this->attach($rateSheet, $sam, 'rate-card-scan.png');

            $call = $this->log($this->deal('Sunrise Foods Inc — commissary crew'), $sam, [
                'channel' => CommunicationChannel::Call,
                'direction' => CommunicationDirection::Outbound,
                'contact_id' => $contact?->id,
                'subject' => 'Call — commissary site walk arranged',
                'body' => 'Confirmed the site walk for Thursday morning and the headcount with operations.',
                'duration_minutes' => 12,
                'outcome' => CommunicationOutcome::Connected,
                'occurred_at' => Carbon::now()->subDay(),
            ]);

            // A logged touch is still the rep's to work, so this one takes another file live.
            $this->attach($call, $sam, 'site-walk-notes.png');

            $this->log($sunrise, $sam, [
                'channel' => CommunicationChannel::Meeting,
                'direction' => CommunicationDirection::Outbound,
                'contact_id' => $contact?->id,
                'subject' => 'Kick-off meeting with the Sunrise HR team',
                'body' => 'Walked the HR team through onboarding, payroll cut-offs and the service level agreement.',
                'duration_minutes' => 45,
                'outcome' => CommunicationOutcome::Connected,
                'occurred_at' => Carbon::now()->subDays(7),
            ]);
        }

        // --- BPO Solutions PH: a failed call attempt, commercial terms out, and a reply back.
        $bpo = $this->company('BPO Solutions PH');

        if ($bpo !== null) {
            $contact = $this->primaryContact($bpo);
            $ramp = $this->deal('BPO Solutions PH — 40-seat contact centre ramp');

            $this->log($bpo, $sam, [
                'channel' => CommunicationChannel::Call,
                'direction' => CommunicationDirection::Outbound,
                'contact_id' => $contact?->id,
                'subject' => 'Call attempt — procurement desk',
                'body' => 'No answer; procurement was in their Monday review. Retry after 3pm.',
                'outcome' => CommunicationOutcome::NoAnswer,
                'occurred_at' => Carbon::now()->subDays(5),
            ]);

            $commercial = $this->log($ramp, $sam, [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Outbound,
                'contact_id' => $contact?->id,
                'subject' => 'Commercial terms for the 40-seat ramp',
                'body' => "Hello,\n\nThe commercial terms for the 40-seat ramp are attached, with the 90-day ramp-down clause your legal team asked about highlighted.\n\nRegards,\nSam",
                'from_name' => $sam->name,
                'from_address' => 'sales@primepower.test',
                'to_name' => $contact?->name,
                'to_address' => $contact?->email,
                'occurred_at' => Carbon::now()->subDays(2),
            ]);

            // A send the gateway rejected, so the retry path demos without touching config.
            $this->log($ramp, $sam, [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Outbound,
                'status' => CommunicationStatus::Failed,
                'failed_reason' => 'The mail provider rejected the message (mocked failure).',
                'contact_id' => $contact?->id,
                'subject' => 'Site visit schedule for the Ortigas floor',
                'body' => "Hello,\n\nProposed schedule for the walkthrough, in case the ramp starts on the earlier date.\n\nRegards,\nSam",
                'from_name' => $sam->name,
                'from_address' => 'sales@primepower.test',
                'to_name' => $contact?->name,
                'to_address' => $contact?->email,
                'occurred_at' => Carbon::now()->subDays(2),
            ]);

            $this->log($ramp, $sam, [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Inbound,
                'contact_id' => $contact?->id,
                // The id the mocked mailbox gives the reply to the message above, so a demo sync
                // recognises it as already pulled instead of inventing a second reply (S2).
                'external_id' => $commercial === null ? null : MockEmailGateway::replyExternalIdFor($commercial->reference),
                'subject' => 'Re: Commercial terms for the 40-seat ramp',
                'body' => "Sam,\n\nLegal is comfortable with the ramp-down clause as drafted. We would like the start date moved one week later to align with the Ortigas handover.\n\nThanks,\nProcurement",
                'from_name' => $contact?->name,
                'from_address' => $contact?->email,
                'to_name' => $sam->name,
                'occurred_at' => Carbon::now()->subDay(),
            ]);
        }

        // --- Harbor Logistics: the signed renewal trail plus the second-shift upsell.
        $harbor = $this->company('Harbor Logistics');

        if ($harbor !== null) {
            $contact = $this->primaryContact($harbor);

            $this->log($this->deal('Harbor Logistics — driver pooling'), $liza, [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Outbound,
                'contact_id' => $contact?->id,
                'subject' => 'Forklift pooling agreement — final copy for signature',
                'body' => "Hi,\n\nFinal copy attached. Once signed, operations will be in touch to schedule the handover briefing.\n\nBest,\nLiza",
                'from_name' => $liza->name,
                'from_address' => 'liza@primepower.test',
                'to_name' => $contact?->name,
                'to_address' => $contact?->email,
                'occurred_at' => Carbon::now()->subDays(28),
            ]);

            $this->log($this->deal('Harbor Logistics — second-shift operators'), $liza, [
                'channel' => CommunicationChannel::Call,
                'direction' => CommunicationDirection::Outbound,
                'contact_id' => $contact?->id,
                'subject' => 'Call — shift differential confirmed with operations',
                'body' => 'Operations confirmed the 15% night differential; waiting on the signed variation.',
                'duration_minutes' => 18,
                'outcome' => CommunicationOutcome::Connected,
                'occurred_at' => Carbon::now()->subDays(3),
            ]);
        }

        // --- Greenfield Hotels Cebu: the inbound enquiry behind the fresh deal.
        $cebu = $this->company('Greenfield Hotels Cebu');

        if ($cebu !== null) {
            $contact = $this->primaryContact($cebu);

            $this->log($this->deal('Greenfield Hotels Cebu — housekeeping crew'), $liza, [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Inbound,
                'contact_id' => $contact?->id,
                'subject' => 'Enquiry — housekeeping crew for the resort launch',
                'body' => "Good day,\n\nWe open the Cebu property in March and need 20 housekeeping staff for the launch. Please send your proposal.\n\nRegards,\nFront Office",
                'from_name' => $contact?->name,
                'from_address' => $contact?->email,
                'to_name' => $liza->name,
                'occurred_at' => Carbon::now()->subDay(),
            ]);
        }
    }

    private function company(string $name): ?Company
    {
        return Company::where('name', $name)->first();
    }

    /**
     * Hang a file off a message exactly as an upload does (module 03 slice S3): the bytes go
     * to the private disk and the row points at them. The placeholder is a real file, so a
     * demo download opens rather than handing back a broken document.
     */
    private function attach(?Communication $communication, User $uploader, string $name): void
    {
        if ($communication === null) {
            return;
        }

        $disk = (string) config('crm.attachments.disk');
        $path = 'attachments/'.$communication->id.'-'.$name;

        Storage::disk($disk)->put($path, base64_decode(self::PLACEHOLDER_PNG, true));

        $communication->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size' => strlen((string) base64_decode(self::PLACEHOLDER_PNG, true)),
            'uploaded_by' => $uploader->id,
        ]);
    }

    private function deal(string $name): ?Opportunity
    {
        return Opportunity::where('name', $name)->first();
    }

    private function primaryContact(Company $company): ?Contact
    {
        return $company->contacts()->where('is_primary', true)->first();
    }

    /**
     * Log one message and mirror it onto the subject's timeline, exactly as the API does.
     *
     * A seeded message that "went out" carries the id the mocked gateway would have given it
     * (slice S2) — that is what ties a reply to its original and keeps a demo sync honest.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function log(Lead|Company|Contact|Opportunity|Contract|null $subject, User $owner, array $attributes): ?Communication
    {
        if ($subject === null) {
            return null;
        }

        /** @var Communication $communication */
        $communication = $subject->communications()->create([
            'channel' => $attributes['channel'],
            'direction' => $attributes['direction'],
            'status' => $attributes['status'] ?? CommunicationStatus::defaultFor($attributes['channel'], $attributes['direction']),
            'contact_id' => $attributes['contact_id'] ?? null,
            'subject' => $attributes['subject'],
            'body' => $attributes['body'] ?? null,
            'from_name' => $attributes['from_name'] ?? null,
            'from_address' => $attributes['from_address'] ?? null,
            'to_name' => $attributes['to_name'] ?? null,
            'to_address' => $attributes['to_address'] ?? null,
            'occurred_at' => $attributes['occurred_at'],
            'duration_minutes' => $attributes['duration_minutes'] ?? null,
            'outcome' => $attributes['outcome'] ?? null,
            'external_id' => $attributes['external_id'] ?? null,
            'failed_reason' => $attributes['failed_reason'] ?? null,
            'owner_id' => $owner->id,
        ]);

        // A sent email went through the mocked mailbox, so it carries the id that gateway
        // assigns (derived from the reference). That is what lets a demo sync recognise a reply
        // it already has, and which record a new one belongs to (slice S2).
        if ($communication->channel === CommunicationChannel::Email
            && $communication->direction === CommunicationDirection::Outbound
            && $communication->status === CommunicationStatus::Sent) {
            $communication->update(['external_id' => MockEmailGateway::externalIdFor($communication->reference)]);
        }

        CommunicationLogged::dispatch($communication, $owner);

        return $communication;
    }
}
