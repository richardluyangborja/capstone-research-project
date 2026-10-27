<?php

namespace App\Http\Companies;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CommunicationStatus;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Opportunity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Account Book's two derived columns (spec 16 §Account Book): the next follow-up
 * across the account and its people, deals and contracts, and the last thing that actually
 * happened on it. Both are read-time values; nothing is stored twice.
 */
final class AccountBookMetrics
{
    /**
     * @param  Collection<int, Company>  $companies
     */
    public static function attach(Collection $companies): void
    {
        if ($companies->isEmpty()) {
            return;
        }

        $subjects = self::subjectMap($companies);
        $next = self::nextFollowUps($subjects);
        $last = self::lastActivity($subjects);

        $companies->each(function (Company $company) use ($next, $last): void {
            $company->setAttribute('next_follow_up_at', $next[$company->id] ?? null);
            $company->setAttribute('last_activity_at', $last[$company->id] ?? null);
        });
    }

    /**
     * Every subject id that belongs to each account — the company itself, its contacts,
     * opportunities and contracts — keyed by morph type, then subject id to company id.
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, array<string, string>>
     */
    private static function subjectMap(Collection $companies): array
    {
        $companyIds = $companies->pluck('id')->all();

        return [
            Company::class => $companies
                ->mapWithKeys(fn (Company $company): array => [$company->id => $company->id])
                ->all(),
            Contact::class => Contact::query()
                ->whereIn('company_id', $companyIds)
                ->pluck('company_id', 'id')
                ->all(),
            Opportunity::class => Opportunity::query()
                ->whereIn('company_id', $companyIds)
                ->pluck('company_id', 'id')
                ->all(),
            Contract::class => Contract::query()
                ->whereIn('company_id', $companyIds)
                ->pluck('company_id', 'id')
                ->all(),
        ];
    }

    /**
     * The earliest owed task or scheduled message on any part of the account.
     *
     * @param  array<string, array<string, string>>  $subjects
     * @return array<string, CarbonInterface>
     */
    private static function nextFollowUps(array $subjects): array
    {
        $next = [];

        Activity::query()
            ->selectRaw('subject_type, subject_id, MIN(due_at) as next_at')
            ->where('type', ActivityType::Task->value)
            ->where('status', ActivityStatus::Open->value)
            ->whereNotNull('due_at')
            ->where(fn (Builder $query) => self::whereSubjects($query, $subjects))
            ->groupBy('subject_type', 'subject_id')
            ->get()
            ->each(function (Activity $task) use (&$next, $subjects): void {
                self::consider($next, $subjects, $task->subject_type, $task->subject_id, Carbon::parse($task->next_at), true);
            });

        Communication::query()
            ->selectRaw('subject_type, subject_id, MIN(scheduled_for) as next_at')
            ->where('status', CommunicationStatus::Scheduled->value)
            ->whereNotNull('scheduled_for')
            ->where(fn (Builder $query) => self::whereSubjects($query, $subjects))
            ->groupBy('subject_type', 'subject_id')
            ->get()
            ->each(function (Communication $message) use (&$next, $subjects): void {
                self::consider($next, $subjects, $message->subject_type, $message->subject_id, Carbon::parse($message->next_at), true);
            });

        return $next;
    }

    /**
     * The newest logged activity or communication on any part of the account.
     *
     * @param  array<string, array<string, string>>  $subjects
     * @return array<string, CarbonInterface>
     */
    private static function lastActivity(array $subjects): array
    {
        $last = [];

        Activity::query()
            ->selectRaw('subject_type, subject_id, MAX(occurred_at) as last_at')
            ->whereNotNull('occurred_at')
            ->where(fn (Builder $query) => self::whereSubjects($query, $subjects))
            ->groupBy('subject_type', 'subject_id')
            ->get()
            ->each(function (Activity $activity) use (&$last, $subjects): void {
                self::consider($last, $subjects, $activity->subject_type, $activity->subject_id, Carbon::parse($activity->last_at), false);
            });

        Communication::query()
            ->selectRaw('subject_type, subject_id, MAX(occurred_at) as last_at')
            ->whereNotNull('occurred_at')
            ->where(fn (Builder $query) => self::whereSubjects($query, $subjects))
            ->groupBy('subject_type', 'subject_id')
            ->get()
            ->each(function (Communication $communication) use (&$last, $subjects): void {
                self::consider($last, $subjects, $communication->subject_type, $communication->subject_id, Carbon::parse($communication->last_at), false);
            });

        return $last;
    }

    /**
     * Narrow a morph query to the subjects of this page.
     *
     * @param  array<string, array<string, string>>  $subjects
     */
    private static function whereSubjects(Builder $query, array $subjects): void
    {
        $query->where(function (Builder $query) use ($subjects): void {
            foreach ($subjects as $type => $ids) {
                if ($ids === []) {
                    continue;
                }

                $query->orWhere(function (Builder $query) use ($type, $ids): void {
                    $query->where('subject_type', $type)->whereIn('subject_id', array_keys($ids));
                });
            }
        });
    }

    /**
     * Keep the earliest or latest moment for the company the subject belongs to.
     *
     * @param  array<string, CarbonInterface>  $map
     * @param  array<string, array<string, string>>  $subjects
     */
    private static function consider(
        array &$map,
        array $subjects,
        string $type,
        string $subjectId,
        ?CarbonInterface $at,
        bool $earliest,
    ): void {
        if ($at === null) {
            return;
        }

        $companyId = $subjects[$type][$subjectId] ?? null;

        if ($companyId === null) {
            return;
        }

        $current = $map[$companyId] ?? null;
        $wins = $current === null
            || ($earliest ? $at->lessThan($current) : $at->greaterThan($current));

        if ($wins) {
            $map[$companyId] = $at->copy();
        }
    }
}
