<?php

namespace Database\Seeders;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Enums\SurveyInvitationStatus;
use App\Enums\SurveyQuestionType;
use App\Events\CommunicationLogged;
use App\Integrations\MockEmailGateway;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fixed demo invitations and responses (no faker — seeded for the proposal demo, module 05 slice
 * S2, spec 13 §Demo data).
 *
 * Every row is written the way the API writes it — a real `Communication` logged through module
 * 03's history path, an invitation with its token and window, and responses through
 * `SurveyResponse::record()` so they are scored exactly like a client's submission. The dates
 * are backdated, which is why the *open* BPO invitation is the live demo of the public form and
 * the Greenfield one is already expired.
 */
class DemoSurveyInvitationSeeder extends Seeder
{
    public function run(): void
    {
        $survey = $this->survey('Post-deployment check-in');

        if ($survey === null) {
            return;
        }

        // --- Harbor Logistics: a happy reply, three weeks after the deployment.
        $harbor = $this->company('Harbor Logistics');

        if ($harbor !== null) {
            $invitation = $this->send($survey, $harbor, Carbon::now()->subDays(24), [
                'message' => 'The crew has been with you a few weeks now — we would like to hear how it is going.',
            ]);

            $this->answer($invitation, Carbon::now()->subDays(21), [
                SurveyQuestionType::Rating->value => 5,
                SurveyQuestionType::Nps->value => 10,
                SurveyQuestionType::Text->value => 'The workers settled in faster than we expected, and your '
                    .'supervisor checks in without being asked. No complaints so far.',
            ]);
        }

        // --- Sunrise Foods Inc: the unhappy one. Slice S4 turns this answer into somebody's job.
        $sunrise = $this->company('Sunrise Foods Inc');

        if ($sunrise !== null) {
            $invitation = $this->send($survey, $sunrise, Carbon::now()->subDays(9), [
                'message' => 'How is the holiday peak going with the new packers?',
            ]);

            $this->answer($invitation, Carbon::now()->subDays(6), [
                SurveyQuestionType::Rating->value => 2,
                SurveyQuestionType::Nps->value => 4,
                SurveyQuestionType::Text->value => 'Two of the packers were reassigned without anyone telling us, '
                    .'and the replacements came a day late. The crew itself is good — the coordination is not.',
            ]);
        }

        // --- BPO Solutions PH: open, and therefore the live demo of the public form.
        $bpo = $this->company('BPO Solutions PH');

        if ($bpo !== null) {
            $this->send($survey, $bpo, Carbon::now()->subDays(2), [
                'message' => 'A quick check-in now that the evening shift is fully staffed.',
            ]);
        }

        // --- Greenfield Hotels: sent, never answered, and already past its window.
        $greenfield = $this->company('Greenfield Hotels');

        if ($greenfield !== null) {
            $this->send($survey, $greenfield, Carbon::now()->subDays(31));
        }

        // --- A withdrawn invitation, so the book shows a cancelled row and no live link.
        $review = $this->survey('Quarterly account review');

        if ($review !== null && $sunrise !== null) {
            $cancelled = $this->send($review, $sunrise, Carbon::now()->subDays(12), [
                'message' => 'Our regular quarterly review — five minutes, if you have them.',
            ]);

            $cancelled->forceFill(['status' => SurveyInvitationStatus::Cancelled])->save();
        }
    }

    /**
     * One send, written as history: the invitation row, the email the client received, and the
     * mirror on the account timeline that a logged communication produces.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function send(Survey $survey, Company $company, Carbon $sentAt, array $attributes = []): SurveyInvitation
    {
        $contact = $this->primaryContact($company);
        $sender = $company->owner ?? User::query()->where('email', 'manager@primepower.test')->firstOrFail();

        /** @var SurveyInvitation $invitation */
        $invitation = SurveyInvitation::create([
            'survey_id' => $survey->id,
            'company_id' => $company->id,
            'contact_id' => $contact?->id,
            'recipient_name' => $contact?->name,
            'recipient_email' => $contact?->email ?? 'hello@'.$this->domain($company),
            'message' => $attributes['message'] ?? null,
            'status' => SurveyInvitationStatus::Sent,
            'sent_at' => $sentAt,
            'expires_at' => $sentAt->copy()->addDays((int) config('crm.surveys.invitation_days')),
            'created_by' => $sender->id,
        ]);

        $email = $invitation->emailContent($sender);

        /** @var Communication $communication */
        $communication = $company->communications()->create([
            'channel' => CommunicationChannel::Email,
            'direction' => CommunicationDirection::Outbound,
            'status' => CommunicationStatus::Sent,
            'contact_id' => $contact?->id,
            'subject' => $email['subject'],
            'body' => $email['body'],
            'from_name' => $sender->name,
            'from_address' => 'sales@primepower.test',
            'to_name' => $invitation->recipient_name,
            'to_address' => $invitation->recipient_email,
            'occurred_at' => $sentAt,
            'external_id' => MockEmailGateway::externalIdFor((string) $invitation->reference),
            'owner_id' => $sender->id,
        ]);

        $invitation->communication()->associate($communication);
        $invitation->save();

        // The same event a live send raises, so the account timeline and "last contacted" agree
        // with the history book without a second seeding path (module 03, ADR-0017 §2).
        CommunicationLogged::dispatch($communication, $sender);

        return $invitation;
    }

    /**
     * One submitted answer set, scored through the same method the public endpoint uses.
     *
     * @param  array<string, int|string>  $values  keyed by question type
     */
    private function answer(SurveyInvitation $invitation, Carbon $submittedAt, array $values): ?SurveyResponse
    {
        $invitation->loadMissing('survey.questions');

        $answers = [];

        foreach ($invitation->survey->questions as $question) {
            $value = $values[$question->type->value] ?? null;

            if ($value === null) {
                continue;
            }

            $answers[] = [
                'question_id' => $question->id,
                'value_number' => $question->type->numericBounds() === null ? null : (int) $value,
                'value_text' => $question->type === SurveyQuestionType::Text ? (string) $value : null,
                'value_options' => null,
            ];
        }

        if ($answers === []) {
            return null;
        }

        return SurveyResponse::record($invitation, $answers, $submittedAt);
    }

    private function survey(string $name): ?Survey
    {
        return Survey::query()->where('name', $name)->first();
    }

    private function company(string $name): ?Company
    {
        return Company::query()->where('name', $name)->first();
    }

    /**
     * The person the survey is addressed to: the account's primary contact when it has one.
     */
    private function primaryContact(Company $company): ?Contact
    {
        return $company->contacts()
            ->where('is_primary', true)
            ->first()
            ?? $company->contacts()->orderBy('created_at')->first();
    }

    /**
     * A fallback address for an account with no contact on file — deterministic, never random.
     */
    private function domain(Company $company): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '', strtolower($company->name)) ?? 'client';

        return $slug.'.example.ph';
    }
}
