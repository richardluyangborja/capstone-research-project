<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed fixed demo data (no faker — the system is seeded for a public proposal, see system-tech.md).
     * All accounts use the password "password".
     */
    public function run(): void
    {
        $team = Team::create(['name' => 'Business Development']);

        $manager = User::create([
            'name' => 'Maria Manager',
            'email' => 'manager@primepower.test',
            'password' => 'password',
            'role' => Role::Manager,
            'team_id' => $team->id,
        ]);

        $team->update(['manager_id' => $manager->id]);

        User::create([
            'name' => 'Sam Salesrep',
            'email' => 'salesrep@primepower.test',
            'password' => 'password',
            'role' => Role::Salesrep,
            'team_id' => $team->id,
        ]);

        // A second salesrep so assignment/workload/transfer are demonstrable (submodule 03).
        User::create([
            'name' => 'Liza Lopez',
            'email' => 'salesrep2@primepower.test',
            'password' => 'password',
            'role' => Role::Salesrep,
            'team_id' => $team->id,
        ]);

        User::create([
            'name' => 'Ada Admin',
            'email' => 'admin@primepower.test',
            'password' => 'password',
            'role' => Role::Admin,
        ]);

        User::create([
            'name' => 'Root Superadmin',
            'email' => 'superadmin@primepower.test',
            'password' => 'password',
            'role' => Role::Superadmin,
        ]);

        $this->call(DemoLeadSeeder::class);
        $this->call(DemoCompanySeeder::class);
        $this->call(DemoContactSeeder::class);
    }
}
