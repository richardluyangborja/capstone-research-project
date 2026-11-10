<?php
namespace App\Policies;
use App\Models\User;
use App\Support\MaintenanceAction;
class MaintenanceActionPolicy {
    public function refreshConfiguration(User $user, MaintenanceAction $action): bool { return $user->role->isAdmin(); }
    public function retryEmail(User $user, MaintenanceAction $action): bool { return $user->role->isAdmin(); }
}
