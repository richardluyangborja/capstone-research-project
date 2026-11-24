<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\AuditSeverity;
use App\Models\Activity;
use App\Models\Company;
use App\Models\EmployeeDiscrepancy;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use App\Notifications\EmployeeDiscrepancyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 09 slice T4 (spec 21 §T4, ADR-0033): a rep escalates a disputed figure, the manager and
 * admins hear about it, and only a manager/admin settles it. Nothing here writes Core 2.
 */
class EmployeeDiscrepancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rep_can_raise_a_flag_on_their_own_record_and_the_manager_and_admins_are_notified(): void
    {
        Notification::fake();

        $team = Team::create(['name' => 'Sales']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $team->update(['manager_id' => $manager->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'attendance',
            'explanation' => 'Marked absent on 24 Oct although HR slip #1049 approved the day.',
        ])->assertCreated();

        $response->assertJsonPath('data.category', 'attendance')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.status_label', 'Under Review');

        $this->assertDatabaseHas('employee_discrepancies', [
            'employee_id' => $rep->id,
            'raised_by' => $rep->id,
            'category' => 'attendance',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.discrepancy_raised',
            'severity' => AuditSeverity::Notice->value,
            'actor_id' => $rep->id,
            'subject_id' => $rep->id,
        ]);

        Notification::assertSentTo([$manager, $admin], EmployeeDiscrepancyNotification::class);
        Notification::assertNotSentTo($rep, EmployeeDiscrepancyNotification::class);
    }

    public function test_a_rep_cannot_raise_a_flag_on_somebody_elses_record(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$teammate->public_id}/discrepancies", [
            'category' => 'attendance',
            'explanation' => 'Their figures look wrong to me, so I am flagging them.',
        ])->assertForbidden();

        $this->assertSame(0, EmployeeDiscrepancy::query()->count());
    }

    public function test_the_flag_is_validated_on_category_explanation_and_reference(): void
    {
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'nonsense',
            'explanation' => 'too short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['category', 'explanation']);

        // An attendance flag lives only in Core 2, so it cannot name a CRM record.
        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'attendance',
            'explanation' => 'The scanner missed my punch on that morning shift.',
            'subject_type' => 'opportunity',
            'subject_id' => (string) Str::ulid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subject_type']);

        // A commercial flag may name one, but only a record the caller can see.
        $foreign = Opportunity::factory()->create(['owner_id' => User::factory()->salesrep()->create()->id]);
        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'missing_deal',
            'explanation' => 'This closed deal never reached my commission statement.',
            'subject_type' => 'opportunity',
            'subject_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);
    }

    public function test_a_flag_on_a_deal_writes_a_system_activity_on_that_deal_only(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $rep = User::factory()->salesrep()->create();
        $deal = Opportunity::factory()->create(['company_id' => $company->id, 'owner_id' => $rep->id]);

        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'missing_deal',
            'explanation' => 'This won deal is missing from my commission statement.',
            'subject_type' => 'opportunity',
            'subject_id' => $deal->id,
        ])->assertCreated()->assertJsonPath('data.subject.id', $deal->id);

        $this->assertDatabaseHas('activities', [
            'subject_type' => $deal->getMorphClass(),
            'subject_id' => $deal->id,
            'type' => ActivityType::System->value,
        ]);

        // Attendance has no CRM subject, so the same rep's attendance flag adds no timeline entry.
        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'attendance',
            'explanation' => 'My morning punch is missing from the attendance sheet.',
        ])->assertCreated();

        $this->assertSame(1, Activity::query()->where('type', ActivityType::System->value)->count());
    }

    public function test_the_employee_and_their_manager_see_the_flags_while_a_teammate_does_not(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $team->update(['manager_id' => $manager->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'leave_balance',
            'explanation' => 'My remaining vacation leave looks one day short this year.',
        ])->assertCreated();

        $this->actingAs($rep, 'api')->getJson("/api/v1/employees/{$rep->public_id}/discrepancies")
            ->assertOk()
            ->assertJsonPath('meta.open', 1)
            ->assertJsonPath('meta.can_raise', true)
            ->assertJsonPath('meta.can_resolve', false);

        $this->actingAs($manager, 'api')->getJson("/api/v1/employees/{$rep->public_id}/discrepancies")
            ->assertOk()
            ->assertJsonPath('meta.can_raise', false)
            ->assertJsonPath('meta.can_resolve', true);

        $this->actingAs($teammate, 'api')->getJson("/api/v1/employees/{$rep->public_id}/discrepancies")
            ->assertForbidden();
    }

    public function test_a_manager_resolves_a_flag_once_and_the_profile_stops_badging_it(): void
    {
        Notification::fake();

        $team = Team::create(['name' => 'Sales']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $team->update(['manager_id' => $manager->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $id = $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'attendance',
            'explanation' => 'The biometric scanner missed my punch on the early shift.',
        ])->assertCreated()->json('data.id');

        // The rep cannot settle their own dispute.
        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies/{$id}/resolve")
            ->assertForbidden();

        $this->actingAs($manager, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies/{$id}/resolve", [
            'note' => 'Confirmed with HR; the punch was restored.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.status_label', 'Resolved')
            ->assertJsonPath('data.resolved.note', 'Confirmed with HR; the punch was restored.');

        $this->assertDatabaseHas('employee_discrepancies', [
            'id' => $id,
            'status' => 'resolved',
            'resolved_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.discrepancy_resolved',
            'severity' => AuditSeverity::Info->value,
            'actor_id' => $manager->id,
        ]);

        // A second resolve is a state error, not a permission one.
        $this->actingAs($manager, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies/{$id}/resolve")
            ->assertUnprocessable();

        // The profile no longer badges the category, and the row is still readable as history.
        $this->actingAs($manager, 'api')->getJson("/api/v1/employees/{$rep->public_id}")
            ->assertOk()
            ->assertJsonPath('data.discrepancies.open_count', 0);
        $this->actingAs($manager, 'api')->getJson("/api/v1/employees/{$rep->public_id}/discrepancies")
            ->assertOk()
            ->assertJsonPath('meta.open', 0)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_profile_and_its_tabs_carry_the_open_categories(): void
    {
        Notification::fake();

        $rep = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')->postJson("/api/v1/employees/{$rep->public_id}/discrepancies", [
            'category' => 'attendance',
            'explanation' => 'The scanner missed my punch on the early shift again.',
        ])->assertCreated();

        $this->actingAs($rep, 'api')->getJson("/api/v1/employees/{$rep->public_id}")
            ->assertOk()
            ->assertJsonPath('data.discrepancies.open', ['attendance'])
            ->assertJsonPath('data.discrepancies.can_raise', true);

        $this->actingAs($rep, 'api')->getJson("/api/v1/employees/{$rep->public_id}/attendance")
            ->assertOk()
            ->assertJsonPath('meta.open_discrepancies', ['attendance']);

        // A teammate may not read the flags, so the detail payload carries no block at all.
        $teammate = User::factory()->salesrep()->create(['team_id' => $rep->team_id]);
        $team = Team::create(['name' => 'Sales']);
        $rep->update(['team_id' => $team->id]);
        $teammate->update(['team_id' => $team->id]);

        $this->actingAs($teammate, 'api')->getJson("/api/v1/employees/{$rep->public_id}")
            ->assertOk()
            ->assertJsonPath('data.discrepancies', null);
    }
}
