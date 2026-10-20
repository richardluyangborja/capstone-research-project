<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The generated reminders (module 04 slice S4, spec 12 §11): the reminders the records imply on
 * their own — a renewal for a contract inside its window, a check-in for a client nobody has
 * contacted, a nudge for a stale lead.
 *
 * Two things are load-bearing here: the run is **idempotent** through `source_key` (a second run
 * creates nothing, next week's run may), and it **never invents an owner** — a record nobody owns
 * is reported as skipped rather than handed to whoever ran it.
 */
class GeneratedFollowUpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 10:00 on a Monday in Manila, in ISO week 43 — the clock is pinned so the week bucket and
     * the proposed due moments cannot drift with the machine running the suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 02:00:00', 'UTC'));
    }

    private function generate(User $user): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson('/api/v1/follow-ups/generate');
    }

    /**
     * The summary block for one source, so a test reads only the half it is about.
     *
     * @return array<string, mixed>
     */
    private function source(TestResponse $response, string $key): array
    {
        foreach ($response->json('data.sources') as $source) {
            if ($source['key'] === $key) {
                return $source;
            }
        }

        $this->fail(sprintf('The summary has no %s source.', $key));
    }

    /**
     * A client that became a client long ago and has never been contacted.
     */
    private function quietClient(User $owner): Company
    {
        return Company::factory()->client()->ownedBy($owner)->create([
            'client_since' => now()->subDays(60)->toDateString(),
        ]);
    }

    private function staleLead(?User $owner): Lead
    {
        $lead = Lead::factory()->create(['owner_id' => $owner?->id]);
        $lead->forceFill(['last_activity_at' => now()->subDays(30)])->save();

        return $lead;
    }

    public function test_a_contract_inside_its_renewal_window_gets_a_renewal_task(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->client()->ownedBy($rep)->create([
            'client_since' => now()->toDateString(),
        ]);
        $contract = Contract::factory()->expiringSoon()->forCompany($company)->create();

        $response = $this->generate($manager)->assertOk();

        $renewal = $this->source($response, 'renewal');

        $this->assertSame(1, $renewal['created']);
        $this->assertSame(0, $renewal['skipped']);

        // Ten days of contract left: a week before the end date, 09:00 in Manila (01:00 UTC).
        $this->assertSame('2026-10-22T01:00:00.000000Z', $renewal['tasks'][0]['due_at']);
        $this->assertSame('Renew '.$contract->title, $renewal['tasks'][0]['subject']);
        $this->assertSame($rep->id, $renewal['tasks'][0]['owner']['id']);
        $this->assertSame('contract', $renewal['tasks'][0]['record']['type']);

        $this->assertDatabaseHas('activities', [
            'type' => ActivityType::Task->value,
            'subject' => 'Renew '.$contract->title,
            'source_key' => 'renewal:'.$contract->id,
            'owner_id' => $rep->id,
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
        ]);
    }

    public function test_a_client_nobody_has_contacted_gets_a_check_in(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->quietClient($rep);

        $response = $this->generate($manager)->assertOk();

        $checkIn = $this->source($response, 'check_in');

        $this->assertSame(1, $checkIn['created']);
        $this->assertSame('Check in with '.$company->name, $checkIn['tasks'][0]['subject']);
        // Tomorrow morning in the business calendar.
        $this->assertSame('2026-10-20T01:00:00.000000Z', $checkIn['tasks'][0]['due_at']);

        $this->assertDatabaseHas('activities', [
            'source_key' => 'check_in:'.$company->id.':2026-W43',
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'owner_id' => $rep->id,
        ]);
    }

    public function test_a_client_contacted_recently_is_not_nudged(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->quietClient($rep);

        Communication::factory()->about($company, $rep)->create([
            'occurred_at' => now()->subDays(5),
        ]);

        $response = $this->generate($manager)->assertOk();

        $this->assertSame(0, $this->source($response, 'check_in')['created']);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_contact_on_a_client_deal_counts_as_contact_with_the_client(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->quietClient($rep);
        $deal = Opportunity::factory()->forCompany($company)->create();

        Communication::factory()->about($deal, $rep)->create([
            'occurred_at' => now()->subWeek(),
        ]);

        $response = $this->generate($manager)->assertOk();

        $this->assertSame(0, $this->source($response, 'check_in')['created']);
    }

    public function test_a_stale_lead_gets_a_nudge_and_a_recent_one_does_not(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $stale = $this->staleLead($rep);
        $recent = Lead::factory()->create(['owner_id' => $rep->id]);
        $recent->forceFill(['last_activity_at' => now()->subDays(2)])->save();

        $response = $this->generate($manager)->assertOk();

        $source = $this->source($response, 'stale_lead');

        $this->assertSame(1, $source['created']);
        $this->assertSame('Follow up with '.$stale->company_name, $source['tasks'][0]['subject']);

        $this->assertDatabaseHas('activities', [
            'subject_id' => $stale->id,
            'subject_type' => Lead::class,
            'source_key' => 'stale_lead:'.$stale->id.':2026-W43',
        ]);
        $this->assertDatabaseMissing('activities', ['subject_id' => $recent->id]);
    }

    public function test_a_record_without_an_owner_is_skipped_and_reported(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = $this->staleLead(null);

        $response = $this->generate($manager)->assertOk();

        $source = $this->source($response, 'stale_lead');

        $this->assertSame(0, $source['created']);
        $this->assertSame(1, $source['skipped']);
        $this->assertSame('The record has no owner.', $source['skips'][0]['reason']);
        $this->assertSame($lead->company_name, $source['skips'][0]['record']['label']);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_a_record_somebody_is_already_working_is_skipped(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $lead = $this->staleLead($rep);

        // A rep's own task on the record: the generator does not pile a second nudge on it.
        Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'due_at' => now()->addDays(2),
        ]);

        $response = $this->generate($manager)->assertOk();

        $source = $this->source($response, 'stale_lead');

        $this->assertSame(0, $source['created']);
        $this->assertSame('Somebody already has follow-up work open on this record.', $source['skips'][0]['reason']);
        $this->assertSame(1, Activity::query()->where('type', ActivityType::Task)->count());
    }

    public function test_a_second_run_creates_nothing(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $this->quietClient($rep);
        $this->staleLead($rep);

        $first = $this->generate($manager)->assertOk();
        $this->assertSame(2, $first->json('data.created'));
        $this->assertSame(0, $first->json('data.skipped'));

        $second = $this->generate($manager)->assertOk();

        $this->assertSame(0, $second->json('data.created'));
        $this->assertSame(2, $second->json('data.skipped'));
        $this->assertSame(
            'A reminder from this run already stands.',
            $this->source($second, 'check_in')['skips'][0]['reason'],
        );
        $this->assertSame(2, Activity::query()->where('type', ActivityType::Task)->count());
    }

    public function test_the_next_iso_week_may_raise_the_next_check_in(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->quietClient($rep);

        $this->generate($manager)->assertOk();

        $this->travel(7)->days();

        $response = $this->generate($manager)->assertOk();

        $this->assertSame(1, $this->source($response, 'check_in')['created']);
        $this->assertSame(2, Activity::query()->where('type', ActivityType::Task)->count());

        $keys = Activity::query()->pluck('source_key')->all();

        $this->assertContains('check_in:'.$company->id.':2026-W43', $keys);
        $this->assertContains('check_in:'.$company->id.':2026-W44', $keys);
    }

    public function test_every_generated_task_tells_its_owner(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $this->quietClient($rep);

        $this->generate($manager)->assertOk();

        $notification = $rep->notifications()->firstOrFail();
        $task = Activity::query()->where('type', ActivityType::Task)->firstOrFail();

        $this->assertSame('follow_up_assigned', $notification->data['type']);
        $this->assertSame('/follow-ups/'.$task->id, $notification->data['url']);
        $this->assertStringContainsString('Assigned to you: Check in with', $notification->data['message']);
    }

    public function test_a_salesrep_may_not_generate(): void
    {
        $this->generate(User::factory()->salesrep()->create())->assertForbidden();

        $this->generate(User::factory()->manager()->create())->assertOk();
    }

    public function test_the_artisan_twin_reports_what_it_did(): void
    {
        $rep = User::factory()->salesrep()->create();
        $this->quietClient($rep);

        $this->artisan('follow-ups:generate')
            ->expectsOutputToContain('1 created, 0 skipped.')
            ->expectsOutputToContain('Check-ins: 1 created, 0 skipped')
            ->assertSuccessful();

        $this->artisan('follow-ups:generate')
            ->expectsOutputToContain('0 created, 1 skipped.')
            ->assertSuccessful();

        $this->assertSame(1, Activity::query()->where('type', ActivityType::Task)->count());
    }

    public function test_the_worklist_marks_a_generated_row(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->quietClient($rep);

        $this->generate($manager)->assertOk();

        // A hand-written task beside it: only the generated one carries a source.
        Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'due_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('subject');

        $generated = $rows['Check in with '.$company->name];

        $this->assertSame('check_in', $generated['source']['value']);
        $this->assertSame('Check-in', $generated['source']['label']);
        $this->assertSame('check_in:'.$company->id.':2026-W43', $generated['source_key']);

        $handWritten = $rows->first(fn (array $row): bool => $row['source_key'] === null);

        $this->assertNotNull($handWritten);
        $this->assertNull($handWritten['source']);
    }
}
