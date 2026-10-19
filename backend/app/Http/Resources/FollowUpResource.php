<?php

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\Communication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the follow-up worklist (module 04, spec 12 §Payloads). The worklist is a read
 * model over the records that hold a promise, so the row starts with `kind` and then shows the
 * record's own shape — a task, or a scheduled message (slice S2). Both read alike on purpose:
 * a row is "something owed, on a record, by a time" whichever table it lives in.
 *
 * @mixin Activity|Communication
 */
class FollowUpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof Communication) {
            return (new ScheduledMessageResource($this->resource))->toArray($request);
        }

        return ['kind' => 'task'] + (new TaskResource($this->resource))->toArray($request);
    }
}
