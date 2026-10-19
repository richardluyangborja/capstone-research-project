<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\FollowUpAssigned;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Requests\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Models\Activity;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Follow-up **tasks** (module 04 slice S1, spec 12): something a person owes, on a record, by a
 * time. A task is an `Activity` with `type = task` — which is why the module adds no table —
 * and every write keeps the record's own `next_follow_up_at` honest, so the lead list and the
 * aging views read one column instead of joining two tables.
 */
class TaskController extends Controller
{
    public function store(StoreTaskRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $subject = Activity::resolveSubjectType($data['subject_type'], $data['subject_id']);

        if ($subject === null) {
            throw ValidationException::withMessages([
                'subject_id' => ['That record does not exist.'],
            ]);
        }

        // Putting work on a record needs the right to work it — the same rule as logging an
        // activity or a message against it (spec 12 §Permissions).
        $this->authorize('update', $subject);

        $owner = $this->resolveOwner($request, $data['owner_id'] ?? null);

        $task = Activity::create([
            'type' => ActivityType::Task,
            'subject' => $data['subject'],
            'body' => $data['body'] ?? null,
            'status' => ActivityStatus::Open,
            'due_at' => Carbon::parse($data['due_at']),
            'owner_id' => $owner->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);

        $task->syncSubjectFollowUp();

        // Handing work to someone else is worth telling them about; putting it on yourself
        // raises the same event and the listener stays quiet (spec 12 §10).
        FollowUpAssigned::dispatch($task, $owner, $user);

        return (new TaskResource($task->load($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Activity $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task->load($this->relations()));
    }

    /**
     * Edit the task's wording, when it is owed or who owes it. Moving `due_at` is a reschedule,
     * which from slice S3 also re-arms the reminder (spec 12 §3); handing it to someone else
     * tells them (spec 12 §10).
     */
    public function update(UpdateTaskRequest $request, Activity $task): TaskResource
    {
        $data = $request->validated();
        $attributes = [];
        $previousOwnerId = $task->owner_id;

        foreach (['subject', 'body'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('due_at', $data)) {
            $attributes['due_at'] = Carbon::parse($data['due_at']);
            // The reminder is re-armed: a task pushed to next week is flagged when next week
            // comes instead of staying silent forever (spec 12 §3).
            $attributes['reminded_at'] = null;
        }

        if (array_key_exists('owner_id', $data)) {
            $attributes['owner_id'] = $this->resolveOwner($request, (int) $data['owner_id'])->id;
        }

        if ($attributes !== []) {
            $task->fill($attributes)->save();
            $task->syncSubjectFollowUp();

            if ($task->owner_id !== $previousOwnerId) {
                FollowUpAssigned::dispatch($task, $task->load('owner')->owner, $request->user());
            }
        }

        return new TaskResource($task->load($this->relations()));
    }

    /**
     * Complete, cancel or reopen a task. The move runs `ActivityStatus::allowedTransitions()`,
     * so an illegal one is a 422 — the caller may act on the task, just not that way.
     */
    public function updateStatus(UpdateTaskStatusRequest $request, Activity $task): TaskResource
    {
        $status = ActivityStatus::from((string) $request->validated('status'));

        if (! $task->status->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => [sprintf(
                    'A %s follow-up cannot move to %s.',
                    $task->status->value,
                    $status->value,
                )],
            ]);
        }

        $task->status = $status;
        $task->save();
        $task->syncSubjectFollowUp();

        return new TaskResource($task->load($this->relations()));
    }

    /**
     * The task as every task payload reads it. The owner's `team_id` is in the column list on
     * purpose: who may move a task follows assignment (ADR-0004) and the policy reads the
     * owner's team, so a thin load would quietly cost a manager their rights.
     *
     * @return array<int, string>
     */
    private function relations(): array
    {
        return ['owner:id,name,team_id', 'subjectable'];
    }

    /**
     * Who owes the task: the caller by default, or someone they may hand work to (ADR-0004).
     */
    private function resolveOwner(Request $request, ?int $ownerId): User
    {
        $user = $request->user();

        if ($ownerId === null || $ownerId === $user->id) {
            return $user;
        }

        $owner = User::findOrFail($ownerId);

        abort_unless(
            TaskPolicy::mayAssignTo($user, $owner),
            403,
            'You may not assign a follow-up to that person.',
        );

        return $owner;
    }
}
