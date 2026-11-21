<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Idempotently ensure the 5 canonical HRMS roles exist in the database.
     * This avoids any mandatory dependency on database seeders.
     */
    public static function ensureStandardRoles(): void
    {
        $hasPermissionsCol = Schema::hasTable('roles') && Schema::hasColumn('roles', 'permissions');

        $standardRoles = [
            [
                'name' => 'admin',
                'display_name' => 'Admin',
                'description' => 'Full administrative control and executive management',
                'permissions' => ['all'],
            ],
            [
                'name' => 'hr',
                'display_name' => 'HR Manager',
                'description' => 'Manages HR operations, employees, and statutory payroll',
                'permissions' => ['dashboard', 'employees', 'recruitment', 'leave', 'attendance', 'payroll', 'documents', 'tasks', 'timesheets', 'assets', 'helpdesk', 'reports', 'announcements'],
            ],
            [
                'name' => 'manager',
                'display_name' => 'Company Manager',
                'description' => 'Manages department heads, team leaders, and projects',
                'permissions' => ['dashboard', 'team', 'employees', 'tasks', 'performance', 'documents', 'attendance', 'leave', 'timesheets', 'helpdesk', 'reports'],
            ],
            [
                'name' => 'team_leader',
                'display_name' => 'Team Leader',
                'description' => 'Manages direct team members and day-to-day task execution',
                'permissions' => ['dashboard', 'team', 'tasks', 'performance', 'documents', 'attendance', 'leave', 'timesheets', 'helpdesk'],
            ],
            [
                'name' => 'employee',
                'display_name' => 'Employee',
                'description' => 'Self-service portal, tasks, attendance, and leave requests',
                'permissions' => ['dashboard', 'payroll', 'tasks', 'performance', 'attendance', 'leave', 'timesheets', 'assets', 'helpdesk', 'documents'],
            ],
        ];

        foreach ($standardRoles as $roleData) {
            $role = self::where('name', $roleData['name'])->first();
            if ($role) {
                if ($hasPermissionsCol && empty($role->permissions)) {
                    $role->permissions = $roleData['permissions'];
                    $role->save();
                }
            } else {
                $createData = [
                    'name' => $roleData['name'],
                    'display_name' => $roleData['display_name'],
                    'description' => $roleData['description'],
                ];
                if ($hasPermissionsCol) {
                    $createData['permissions'] = $roleData['permissions'];
                }
                self::create($createData);
            }
        }
    }

    /**
     * Safely lookup a role by name or alias, ensuring standard roles are present if not found.
     */
    public static function getByName(?string $name): self
    {
        $normalized = strtolower(trim((string) $name));
        if (in_array($normalized, ['company_manager', 'manager'])) {
            $normalized = 'manager';
        } elseif (in_array($normalized, ['tl', 'team_lead', 'team_leader'])) {
            $normalized = 'team_leader';
        } elseif (empty($normalized)) {
            $normalized = 'employee';
        }

        $role = self::where('name', $normalized)->first();
        if (!$role) {
            self::ensureStandardRoles();
            $role = self::where('name', $normalized)->first() 
                ?? self::where('name', 'employee')->first()
                ?? self::firstOrCreate(
                    ['name' => 'employee'],
                    [
                        'display_name' => 'Employee',
                        'description' => 'Employee self-service'
                    ]
                );
        }

        return $role;
    }
}
