<?php

namespace Database\Seeders;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\FollowUpDueState;
use App\Events\FollowUpAssigned;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fixed demo follow-ups (module 04 slice S1, no faker): the worklist needs no setup for a
 * demo, and every state it can show is already in the book — overdue, due today, upcoming,
 * done and cancelled.
 *
 * Runs after the lead/company/opportunity seeders because every task hangs off one of those
 * records, and after module 03's history so the subjects read the same. Two owners (Sam and
 * Liza) are deliberate: **Everyone / Mine / My team** and the manager's per-rep filter all demo
 * unseeded. The one task on a lead is what makes `next_follow_up_at` visible in the lead list.
 */
class DemoFollowUpSeeder extends Seeder
{
    public function run(): void
    {
        $sam = User::where('email', 'salesrep@primepower.test')->firstOrFail();
        $liza = User::where('email', 'salesrep2@primepower.test')->firstOrFail();

        $sunrise = Company::where('name', 'Sunrise Foods Inc')->firstOrFail();
        $bpo = Company::where('name', 'BPO Solutions PH')->firstOrFail();
        $bpoDeal = Opportunity::where('name', 'BPO Solutions PH — 40-seat contact centre ramp')->firstOrFail();
        $harborLead = Lead::where('company_name', 'Harbor Logistics')->firstOrFail();

        // Overdue, so the worklist opens on something late and S3's due run has a subject.
        $this->task($sunrise, $sam, [
            'subject' => 'Send the revised rate sheet',
            'body' => 'Cover the two supervisor roles we discussed; rates hold for 30 days.',
            'due_at' => Carbon::now()->subDays(2),
        ]);

        // Due later today, in the business day the worklist is computed against. Sam handed it
        // over, so the bell has a real assignment to show (slice S3).
        $this->task($bpoDeal, $liza, [
            'subject' => 'Confirm the 40-seat start date',
            'body' => 'Carla needs the ramp-up week in writing before the board meeting.',
            'due_at' => $this->laterToday(),
        ], $sam);

        $this->task($bpo, $sam, [
            'subject' => 'Chase the signed contract',
            'body' => 'The service agreement has been with their legal team for a week.',
            'due_at' => Carbon::now()->addDays(2),
        ]);

        // The settled side of the worklist: one finished, one dropped.
        $this->task($sunrise, $sam, [
            'subject' => 'Prepare the crew schedule for the holiday peak',
            'body' => 'Two shifts, 12 packers and 3 forklift operators.',
            'due_at' => Carbon::now()->subDays(4),
            'status' => ActivityStatus::Done,
        ]);

        $this->task($sunrise, $sam, [
            'subject' => 'Book a site visit at the Balintawak commissary',
            'body' => 'Rescheduled to next quarter — the client is not ready for a walkthrough.',
            'due_at' => Carbon::now()->subDays(6),
            'status' => ActivityStatus::Cancelled,
        ]);

        // The one task on a lead: this is what fills the list's "next follow-up" column.
        $this->task($harborLead, $liza, [
            'subject' => 'Confirm the forklift operator shortlist with Dan',
            'body' => 'Fifteen operators needed at Manila South Harbor next month.',
            'due_at' => Carbon::now()->addDays(3),
        ], $sam);
    }

    /**
     * A follow-up task exactly as `POST /tasks` writes one — so the seeded rows carry the
     * record's own link and every write path that follows (a transition, a reschedule) behaves
     * the same on a seeded row as on a hand-made one.
     *
     * When `$assignedBy` is given the assignment is raised the way the API raises it, so the
     * bell opens with a real notification rather than a fabricated row (slice S3).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function task(Model $subject, User $owner, array $attributes, ?User $assignedBy = null): Activity
    {
        /** @var Activity $task */
        $task = $subject->activities()->create([
            'type' => ActivityType::Task,
            'subject' => $attributes['subject'],
            'body' => $attributes['body'] ?? null,
            'status' => $attributes['status'] ?? ActivityStatus::Open,
            'due_at' => $attributes['due_at'],
            'owner_id' => $owner->id,
        ]);

        // Keep the subject's own next-follow-up column honest (spec 12 §12).
        $task->syncSubjectFollowUp();

        if ($assignedBy !== null) {
            FollowUpAssigned::dispatch($task, $owner, $assignedBy);
        }

        return $task;
    }

    /**
     * A moment later today in the business calendar that has not passed yet — "due today"
     * should read as today whenever the demo is seeded. Aimed two hours out and clamped to the
     * last second of the PH day, so a late-evening seed still lands today.
     */
    private function laterToday(): Carbon
    {
        $now = Carbon::now();
        $endOfDay = FollowUpDueState::endOfBusinessDay($now);
        $twoHoursOut = $now->copy()->addHours(2);

        return $twoHoursOut->lessThan($endOfDay) ? $twoHoursOut : $endOfDay->copy()->subSecond();
    }
}
