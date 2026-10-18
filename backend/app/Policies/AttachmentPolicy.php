<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A file inherits the rights of the record it hangs off (module 03 §Permissions, ADR-0017 §5):
 * whoever may see the record may read its files, and whoever may work the record may add or
 * remove them — a salesrep on their own record, a manager across the team, an admin anywhere.
 *
 * Whether the message still *takes* files at all is a state question (it has to be either still
 * editable or never delivered), so it is raised as a 422 in the controller, exactly as the body
 * rule is (ADR-0017 §6). `create` takes the record rather than an attachment, because there is
 * no attachment yet when a file is uploaded.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->maySeeRecord($user, $attachment);
    }

    public function create(User $user, Model $attachable): bool
    {
        return $user->can('update', $attachable);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        $record = $attachment->attachable;

        return $record !== null && $user->can('update', $record);
    }

    /**
     * The file lives only as long as the record it belongs to is visible.
     */
    private function maySeeRecord(User $user, Attachment $attachment): bool
    {
        $record = $attachment->attachable;

        return $record !== null && $user->can('view', $record);
    }
}
