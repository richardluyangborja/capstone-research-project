<?php

namespace Tests\Feature;

use App\Enums\FollowUpDueState;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The due state a follow-up reads as (module 04, spec 12 §7). Derived on read against the
 * Philippine business day, and — importantly — derived *without* rewriting the instants it is
 * handed, because they are usually a model's own attributes.
 */
class FollowUpDueStateTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 on Monday in Manila.
        $this->now = Carbon::parse('2026-10-19 02:00:00', 'UTC');
    }

    public function test_it_reads_the_business_day(): void
    {
        $ph = fn (string $moment): Carbon => Carbon::parse($moment, 'Asia/Manila');

        $this->assertSame(FollowUpDueState::Overdue, FollowUpDueState::for($ph('2026-10-19 09:30:00'), $this->now));
        $this->assertSame(FollowUpDueState::Today, FollowUpDueState::for($ph('2026-10-19 10:30:00'), $this->now));
        $this->assertSame(FollowUpDueState::Upcoming, FollowUpDueState::for($ph('2026-10-20 01:00:00'), $this->now));
        $this->assertSame(FollowUpDueState::Upcoming, FollowUpDueState::for($ph('2026-10-26 09:00:00'), $this->now));
        $this->assertNull(FollowUpDueState::for(null, $this->now));
    }

    public function test_a_late_moment_today_is_overdue_not_today(): void
    {
        // 09:59 Manila is a minute past and still the same day.
        $late = Carbon::parse('2026-10-19 01:59:00', 'UTC');

        $this->assertSame(FollowUpDueState::Overdue, FollowUpDueState::for($late, $this->now));
        $this->assertTrue(FollowUpDueState::Overdue->isOverdue());
        $this->assertFalse(FollowUpDueState::Today->isOverdue());
    }

    public function test_days_until_is_signed_and_counts_business_days(): void
    {
        $ph = fn (string $moment): Carbon => Carbon::parse($moment, 'Asia/Manila');

        $this->assertSame(2, FollowUpDueState::daysUntil($ph('2026-10-21 09:00:00'), $this->now));
        $this->assertSame(0, FollowUpDueState::daysUntil($ph('2026-10-19 23:00:00'), $this->now));
        $this->assertSame(-3, FollowUpDueState::daysUntil($ph('2026-10-16 09:00:00'), $this->now));
    }

    public function test_the_business_day_ends_at_manila_midnight(): void
    {
        $endOfDay = FollowUpDueState::endOfBusinessDay($this->now);

        // 23:59:59 Manila on the 19th is 15:59:59 UTC.
        $this->assertSame('2026-10-19 15:59:59', $endOfDay->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_never_rewrites_the_instants_it_is_handed(): void
    {
        $due = Carbon::parse('2026-10-21 09:00:00', 'Asia/Manila');
        $now = $this->now->copy();
        $dueBefore = $due->format('Y-m-d H:i:s.u e');
        $nowBefore = $now->format('Y-m-d H:i:s.u e');

        FollowUpDueState::for($due, $now);
        FollowUpDueState::daysUntil($due, $now);
        FollowUpDueState::endOfBusinessDay($now);
        FollowUpDueState::block($due, $now);

        $this->assertSame($dueBefore, $due->format('Y-m-d H:i:s.u e'));
        $this->assertSame($nowBefore, $now->format('Y-m-d H:i:s.u e'));
    }

    public function test_the_block_is_what_the_api_returns(): void
    {
        $due = Carbon::parse('2026-10-19 02:30:00', 'UTC');

        $this->assertSame([
            'state' => 'today',
            'label' => 'Due today',
            'days' => 0,
            'overdue' => false,
        ], FollowUpDueState::block($due, $this->now));

        $this->assertNull(FollowUpDueState::block(null, $this->now));
    }
}
