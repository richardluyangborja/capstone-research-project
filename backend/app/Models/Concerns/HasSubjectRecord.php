<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The record a polymorphic entry hangs off. Shared by `Activity` (the quick log) and
 * `Communication` (the detailed history) so both answer "which record is this about?" and
 * "who may see it?" the same way — one map, two records (spec 11 §1, ADR-0017 §5).
 */
trait HasSubjectRecord
{
    /**
     * The subject types an entry may hang off, keyed by the short type the UI links with.
     *
     * @return array<string, class-string>
     */
    public static function subjectTypes(): array
    {
        return [
            'lead' => Lead::class,
            'company' => Company::class,
            'contact' => Contact::class,
            'opportunity' => Opportunity::class,
            'contract' => Contract::class,
        ];
    }

    /**
     * The short record type used by the UI to build a link (`lead`, `company`, …).
     */
    public function recordType(): ?string
    {
        $type = array_search($this->subject_type, self::subjectTypes(), true);

        return $type === false ? null : $type;
    }

    /**
     * A human label for the record this entry hangs off.
     */
    public function recordLabel(): ?string
    {
        $subject = $this->subjectable;

        return match (true) {
            $subject instanceof Lead => $subject->company_name,
            $subject instanceof Company => $subject->name,
            $subject instanceof Contact => $subject->name,
            $subject instanceof Opportunity => $subject->name,
            $subject instanceof Contract => $subject->title,
            default => null,
        };
    }

    /**
     * The `record` block the API returns for an entry — the same shape for a message and a
     * follow-up, so the client links either by its type. Expects `subjectable` to be loaded.
     *
     * @return array{type: string, id: string, label: string|null}|null
     */
    public function recordBlock(): ?array
    {
        $type = $this->recordType();

        return $type === null ? null : [
            'type' => $type,
            'id' => $this->subject_id,
            'label' => $this->recordLabel(),
        ];
    }

    /**
     * Resolve a `{subject_type, subject_id}` pair from a request into the record it names, or
     * null when the type is unknown or the record is gone (the caller turns that into a 422).
     */
    public static function resolveSubjectType(string $type, string $id): ?Model
    {
        $class = self::subjectTypes()[$type] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }

    /**
     * An entry is visible if and only if its subject is visible (ADR-0017 §5) — a log line
     * never outlives the visibility of the record it belongs to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $subjects = [
            Lead::class => Lead::query()->visibleTo($user),
            Company::class => Company::query()->visibleTo($user),
            Contact::class => Contact::query()->visibleTo($user),
            Opportunity::class => Opportunity::query()->visibleTo($user),
            Contract::class => Contract::query()->visibleTo($user),
        ];

        return $query->where(function (Builder $query) use ($subjects): void {
            foreach ($subjects as $type => $subjectQuery) {
                $query->orWhere(function (Builder $query) use ($type, $subjectQuery): void {
                    $query->where('subject_type', $type)
                        ->whereIn('subject_id', $subjectQuery->select('id'));
                });
            }
        });
    }
}
