<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Salesreps the caller may assign leads to (spec 03 §2):
     * salesrep → self, manager → their team, admin/superadmin → all.
     * Includes each salesrep's open-lead count for workload balancing.
     */
    public function assignable(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = User::query()
            ->where('role', Role::Salesrep)
            ->withCount(['leads as open_leads_count' => fn ($query) => $query->whereIn('status', [
                LeadStatus::New->value,
                LeadStatus::Contacted->value,
                LeadStatus::Qualified->value,
                LeadStatus::Nurture->value,
            ])])
            ->orderBy('name');

        if ($user->role === Role::Manager) {
            $query->where('team_id', $user->team_id);
        } elseif ($user->role === Role::Salesrep) {
            $query->whereKey($user->id);
        }

        return response()->json([
            'data' => $query->get(['id', 'name', 'team_id']),
        ]);
    }
}
