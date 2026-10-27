<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Role;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeders safely and idempotently.
     * Seeds essential master setup (Roles, Org, Branch, Shifts, Leave Types) and single Admin login.
     * Removes all fake dummy employees, dummy tasks, dummy attendance, and candidates.
     */
    public function run(): void
    {
        // 1. Ensure Standard Roles exist
        Role::ensureStandardRoles();
        $adminRole = Role::getByName('admin');

        // 2. Organization (Idempotent)
        $org = Organization::firstOrCreate(
            ['code' => 'BLUEBOXX'],
            [
                'name' => 'BLUEBOXX HRMS Enterprise Pvt Ltd',
                'settings' => [
                    'timezone' => 'Asia/Kolkata',
                    'currency' => 'INR',
                    'fiscal_year_start' => '04-01',
                    'logo_url' => '/images/logoblue.png',
                    'icon_logo_url' => '/images/Boxxlogo.png',
                    'holiday_calendar' => [
                        ['date' => '2026-01-26', 'title' => 'Republic Day'],
                        ['date' => '2026-08-15', 'title' => 'Independence Day'],
                        ['date' => '2026-10-02', 'title' => 'Gandhi Jayanti'],
                        ['date' => '2026-10-20', 'title' => 'Diwali'],
                        ['date' => '2026-12-25', 'title' => 'Christmas'],
                    ]
                ]
            ]
        );

        // 3. Branches, Locations & Shifts
        $hqBranch = Branch::firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'HQ-BDQ'],
            [
                'name' => 'Headquarters (Vadodara)',
                'address' => 'SF 02, INDIA BULLS MEGA MALL, Dinesh Mill Rd, near Swami Vivekananda Railway Over Bridge, Anand Nagar, Akota, Vadodara, Gujarat 390022',
                'status' => 'active',
            ]
        );

        $vadodaraLocation = Location::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'India Bulls Mega Mall'],
            [
                'city' => 'Vadodara',
                'state' => 'Gujarat',
                'country' => 'India',
                'postal_code' => '390022',
            ]
        );

        $generalShift = Shift::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'General Day Shift (10:00 AM - 06:00 PM)'],
            [
                'start_time' => '10:00:00',
                'end_time' => '18:00:00',
                'grace_period_minutes' => 15,
                'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            ]
        );

        // 4. Default Password
        $defaultPassword = Hash::make('xeoexperts@2026');

        // 5. Admin User (Only master login kept)
        $admin = User::where('email', 'admin@xeoexperts.com')->orWhere('employee_code', 'EMP001')->first();
        $adminData = [
            'email' => 'admin@xeoexperts.com',
            'employee_code' => 'EMP001',
            'password' => $defaultPassword,
            'organization_id' => $org->id,
            'role_id' => $adminRole->id,
            'branch_id' => $hqBranch->id,
            'location_id' => $vadodaraLocation->id,
            'shift_id' => $generalShift->id,
            'name' => 'Admin User',
            'department' => 'Executive',
            'designation' => 'Director of Operations',
            'joining_date' => '2022-01-10',
            'status' => 'active',
            'phone' => '+91 98765 43210',
            'base_salary' => 150000.00,
            'gender' => 'Male',
            'work_mode' => 'office',
            'probation_status' => 'confirmed',
            'pan' => 'ABCDE1234F',
            'tax_regime' => 'new',
        ];

        if ($admin) {
            $admin->update($adminData);
        } else {
            $admin = User::create($adminData);
        }

        // 6. Master Leave Types
        $casual = LeaveType::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Casual Leave'],
            ['annual_quota' => 12, 'is_paid' => true]
        );
        $sick = LeaveType::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Sick Leave'],
            ['annual_quota' => 10, 'is_paid' => true]
        );
        $earned = LeaveType::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Earned Leave'],
            ['annual_quota' => 15, 'is_paid' => true]
        );

        LeaveBalance::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $admin->id, 'leave_type_id' => $casual->id],
            ['allocated' => 12, 'used' => 0, 'remaining' => 12]
        );
        LeaveBalance::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $admin->id, 'leave_type_id' => $sick->id],
            ['allocated' => 10, 'used' => 0, 'remaining' => 10]
        );
        LeaveBalance::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $admin->id, 'leave_type_id' => $earned->id],
            ['allocated' => 15, 'used' => 0, 'remaining' => 15]
        );
    }
}
