<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddTeamMemberRequest extends FormRequest
{
    /**
     * The controller owns this gate through `authorizeOrFail` so a refused mutation still
     * appends its `security.access_denied` audit event (spec 19 §Audit event vocabulary).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A member is named by the ULID `public_id`. The account must be active and inside the
     * caller's role scope (spec 19 §S3 acceptance "admins cannot assign a user to an
     * out-of-scope role"): an admin never moves an admin or superadmin, a superadmin never
     * moves a superadmin, and no route puts a superadmin on a sales team.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'ulid', Rule::exists('users', 'public_id')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $team = $this->route('team');
                $target = User::query()->where('public_id', (string) $this->input('user_id'))->first();
                $actor = $this->user();

                if ($team === null || $target === null || $actor === null) {
                    return;
                }

                if ($target->role === Role::Superadmin) {
                    $validator->errors()->add('user_id', 'A superadmin account cannot join a team.');

                    return;
                }

                if ($actor->role !== Role::Superadmin && $target->role === Role::Admin) {
                    $validator->errors()->add('user_id', 'Only a superadmin may move an administrator into a team.');

                    return;
                }

                if ($target->status !== UserStatus::Active) {
                    $validator->errors()->add('user_id', 'A suspended account cannot join a team.');

                    return;
                }

                if ($target->team_id === $team->id) {
                    $validator->errors()->add('user_id', 'This account already belongs to the team.');
                }
            },
        ];
    }
}
