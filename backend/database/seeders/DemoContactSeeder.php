<?php

namespace Database\Seeders;

use App\Enums\ContactRoleType;
use App\Enums\ContactStatus;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Fixed demo contacts (no faker — seeded for the proposal demo, see system-tech.md).
 *
 * Covers the shapes the Contacts UI can show: every decision-maker type, a primary contact
 * per company, a shared company with several people, and one deactivated contact that stays
 * in history but drops out of pickers.
 */
class DemoContactSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed('Sunrise Foods Inc', [
            [
                'name' => 'Ramon Villanueva',
                'position' => 'Plant HR Manager',
                'role_type' => ContactRoleType::HiringManager,
                'email' => 'ramon.villanueva@sunrisefoods.ph',
                'phone' => '+63 2 8712 4455',
                'mobile' => '0917 555 2210',
                'is_primary' => true,
            ],
            [
                'name' => 'Grace Lim',
                'position' => 'Recruitment Officer',
                'role_type' => ContactRoleType::Hr,
                'email' => 'grace.lim@sunrisefoods.ph',
                'mobile' => '0918 442 7781',
            ],
            [
                'name' => 'Eduardo Tan',
                'position' => 'Finance Controller',
                'role_type' => ContactRoleType::Finance,
                'email' => 'eduardo.tan@sunrisefoods.ph',
                'mobile' => '0920 118 3390',
            ],
        ]);

        $this->seed('BPO Solutions PH', [
            [
                'name' => 'Cherry Mendoza',
                'position' => 'Talent Acquisition Lead',
                'role_type' => ContactRoleType::Hr,
                'email' => 'cherry.mendoza@bposolutions.ph',
                'phone' => '+63 2 7963 1100',
                'mobile' => '0917 220 4455',
                'is_primary' => true,
            ],
            [
                'name' => 'Mark Reyes',
                'position' => 'Procurement Manager',
                'role_type' => ContactRoleType::Procurement,
                'email' => 'mark.reyes@bposolutions.ph',
                'mobile' => '0921 776 8890',
            ],
            [
                'name' => 'Angela Cruz',
                'position' => 'Country Operations Director',
                'role_type' => ContactRoleType::Executive,
                'email' => 'angela.cruz@bposolutions.ph',
                'mobile' => '0917 309 5566',
            ],
        ]);

        $this->seed('Harbor Logistics', [
            [
                'name' => 'Neil Santiago',
                'position' => 'Operations Procurement Head',
                'role_type' => ContactRoleType::Procurement,
                'email' => 'neil.santiago@harborlogistics.ph',
                'phone' => '+63 2 8527 7788',
                'mobile' => '0918 665 1122',
                'is_primary' => true,
            ],
            [
                'name' => 'Joanna Reyes',
                'position' => 'Finance Manager',
                'role_type' => ContactRoleType::Finance,
                'email' => 'joanna.reyes@harborlogistics.ph',
                'mobile' => '0919 224 6677',
            ],
            [
                'name' => 'Dennis Uy',
                'position' => 'Fleet Supervisor',
                'role_type' => ContactRoleType::HiringManager,
                'email' => 'dennis.uy@harborlogistics.ph',
                'mobile' => '0906 441 2299',
                // Left the company — kept on the account, hidden from pickers (spec 07 §6).
                'status' => ContactStatus::Inactive,
            ],
        ]);

        $this->seed('Greenfield Hotels', [
            [
                'name' => 'Patricia Gomez',
                'position' => 'Group General Manager',
                'role_type' => ContactRoleType::Executive,
                'email' => 'patricia.gomez@greenfieldhotels.ph',
                'phone' => '+63 2 8845 9010',
                'mobile' => '0917 889 3321',
                'is_primary' => true,
            ],
            [
                'name' => 'Ferdinand Ramos',
                'position' => 'Housekeeping Director',
                'role_type' => ContactRoleType::HiringManager,
                'email' => 'ferdinand.ramos@greenfieldhotels.ph',
                'mobile' => '0918 556 7743',
            ],
        ]);

        $this->seed('Greenfield Hotels Cebu', [
            [
                'name' => 'Mia Santos',
                'position' => 'Resort HR Officer',
                'role_type' => ContactRoleType::HiringManager,
                'email' => 'mia.santos@greenfieldhotels.ph',
                'phone' => '+63 32 231 4488',
                'mobile' => '0917 664 8890',
                'is_primary' => true,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     */
    private function seed(string $companyName, array $contacts): void
    {
        $company = Company::where('name', $companyName)->first();

        if ($company === null) {
            return;
        }

        foreach ($contacts as $attributes) {
            $company->contacts()->create([
                'status' => ContactStatus::Active,
                ...$attributes,
            ]);
        }
    }
}
