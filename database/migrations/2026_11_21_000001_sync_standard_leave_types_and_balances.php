<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Realign standard leave types and balances to Personal (5), Casual (5), Privilege (PL) (5)
     * and delete obsolete Sick Leave and Earned Leave across all organizations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('leave_types')) {
            return;
        }

        // 1. Delete old Sick Leave and Earned Leave balances
        $obsoleteTypeIds = DB::table('leave_types')
            ->whereIn('name', ['Sick Leave', 'Earned Leave'])
            ->pluck('id')
            ->toArray();

        if (!empty($obsoleteTypeIds) && Schema::hasTable('leave_balances')) {
            DB::table('leave_balances')->whereIn('leave_type_id', $obsoleteTypeIds)->delete();
        }

        if (!empty($obsoleteTypeIds) && Schema::hasTable('leave_requests')) {
            // Unlink or keep requests safe
        }

        // Delete obsolete leave types
        if (!empty($obsoleteTypeIds)) {
            DB::table('leave_types')->whereIn('id', $obsoleteTypeIds)->delete();
        }

        // 2. Ensure the standard 3 leave types exist: Personal Leave (5), Casual Leave (5), Privilege Leave (PL) (5)
        $standardTypes = [
            ['name' => 'Personal Leave', 'annual_quota' => 5],
            ['name' => 'Casual Leave', 'annual_quota' => 5],
            ['name' => 'Privilege Leave (PL)', 'annual_quota' => 5],
        ];

        // Get all organizations or default org id 1
        $orgIds = [1];
        if (Schema::hasTable('organizations')) {
            $dbOrgIds = DB::table('organizations')->pluck('id')->toArray();
            if (!empty($dbOrgIds)) {
                $orgIds = $dbOrgIds;
            }
        }

        foreach ($orgIds as $orgId) {
            foreach ($standardTypes as $st) {
                $existing = DB::table('leave_types')
                    ->where('name', $st['name'])
                    ->where(function ($q) use ($orgId) {
                        $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                    })
                    ->first();

                if ($existing) {
                    DB::table('leave_types')
                        ->where('id', $existing->id)
                        ->update(['annual_quota' => 5]);
                } else {
                    DB::table('leave_types')->insert([
                        'organization_id' => $orgId,
                        'name' => $st['name'],
                        'annual_quota' => 5,
                        'is_paid' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // 3. For each active user, sync their leave balances to 5, 5, 5
            if (Schema::hasTable('users') && Schema::hasTable('leave_balances')) {
                $users = DB::table('users')->where('organization_id', $orgId)->get();
                $currentTypes = DB::table('leave_types')->where('organization_id', $orgId)->get();

                foreach ($users as $u) {
                    foreach ($currentTypes as $ct) {
                        $bal = DB::table('leave_balances')
                            ->where('user_id', $u->id)
                            ->where('leave_type_id', $ct->id)
                            ->first();

                        if (!$bal) {
                            DB::table('leave_balances')->insert([
                                'organization_id' => $orgId,
                                'user_id' => $u->id,
                                'leave_type_id' => $ct->id,
                                'allocated' => 5,
                                'used' => 0,
                                'remaining' => 5,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        } else {
                            // Update allocated if it was old quota (10, 12, 15)
                            if ($bal->allocated > 5) {
                                DB::table('leave_balances')
                                    ->where('id', $bal->id)
                                    ->update([
                                        'allocated' => 5,
                                        'remaining' => max(0, 5 - $bal->used),
                                        'updated_at' => now(),
                                    ]);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
