<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class BulkTransferLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || $user->role === Role::Salesrep) {
            return false;
        }

        $from = User::find($this->input('from_user_id'));
        $to = User::find($this->input('to_user_id'));

        if ($from === null || $from->role !== Role::Salesrep) {
            return false;
        }

        if ($to === null || $to->role !== Role::Salesrep) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        // Manager: both salesreps must be in the manager's team.
        return $from->team_id !== null
            && $from->team_id === $user->team_id
            && $to->team_id === $user->team_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_user_id' => ['required', 'integer', 'exists:users,id'],
            'to_user_id' => ['required', 'integer', 'different:from_user_id', 'exists:users,id'],
        ];
    }
}
