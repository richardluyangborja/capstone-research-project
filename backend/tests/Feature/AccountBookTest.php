<?php

namespace Tests\Feature;

use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_book_carries_counts_next_follow_up_and_last_activity(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $company = Company::factory()->ownedBy($rep)->create(['name' => 'Sunrise Foods Inc']);
        $contact = Contact::factory()->forCompany($company)->primary()->create();
        $openDeal = Opportunity::factory()->forCompany($company)->ownedBy($rep)
            ->stage(OpportunityStage::Negotiation)->create();
        Opportunity::factory()->forCompany($company)->ownedBy($rep)
            ->stage(OpportunityStage::Won)->create();
        Contract::factory()->forCompany($company)->create();

        $nextTask = Activity::factory()->task()->create([
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'owner_id' => $rep->id,
            'due_at' => now()->addDays(2),
        ]);

        // A second shape, on a child record and later than the task, must not win the column.
        Communication::factory()->scheduled(now()->addDays(3))->about($openDeal, $rep)->create();

        Activity::factory()->create([
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
            'owner_id' => $rep->id,
            'occurred_at' => now()->subDay(),
        ]);

        $lastMessage = Communication::factory()->about($company, $rep)->create([
            'occurred_at' => now()->subHours(2),
        ]);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sunrise Foods Inc')
            ->assertJsonPath('data.0.counts.opportunities', 2)
            ->assertJsonPath('data.0.counts.open_opportunities', 1)
            ->assertJsonPath('data.0.counts.contracts', 1)
            ->assertJsonPath('data.0.next_follow_up.state', 'upcoming')
            ->assertJsonPath('data.0.next_follow_up_at', $nextTask->due_at?->toISOString())
            ->assertJsonPath('data.0.last_activity_at', $lastMessage->occurred_at?->toISOString());

        // The detail read carries the same open count, so the book and Account 360 agree.
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.opportunities', 2)
            ->assertJsonPath('data.counts.open_opportunities', 1);
    }
}
