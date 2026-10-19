<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the follow-up worklist (module 04, spec 12 §Payloads). The worklist is a read
 * model over the records that hold a promise, so the row starts with `kind` and then shows the
 * record's own shape — a task today; a scheduled message joins it in slice S2.
 *
 * @mixin Activity
 */
class FollowUpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['kind' => 'task'] + (new TaskResource($this->resource))->toArray($request);
    }
}
