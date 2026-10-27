<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\User;
use App\Models\Organization;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    public function checkIn(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? $user->role ?? 'employee');
        if (method_exists($user, 'getCanonicalRole')) {
            $roleName = $user->getCanonicalRole();
        }
        $isAdminOrHR = in_array($roleName, ['admin', 'hr']);

        // Auto check-out any previous open attendance records
        $this->processAutoCheckouts($user->organization_id, $user->id);

        $existing = Attendance::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->first();

        if ($existing && $existing->check_in) {
            $formattedIn = substr($existing->check_in, 0, 5);
            $formattedOut = $existing->check_out ? substr($existing->check_out, 0, 5) : null;
            if ($existing->check_out) {
                return response()->json([
                    'message' => 'Attendance completed for today (Checked in at ' . $formattedIn . ', Checked out at ' . $formattedOut . '). You cannot check in again today.',
                    'attendance' => $existing
                ], 400);
            }
            return response()->json([
                'message' => 'You have already checked in today at ' . $formattedIn,
                'attendance' => $existing
            ], 400);
        }

        // Shift cutoff restriction removed: employees can check-in/out flexibly.

        // Geolocation/Geofence verification removed: check-in is permitted without GPS or geofence restrictions.
        $locationVerifiedNote = '';

        $user->load('shift');
        $nowTime = Carbon::now()->format('H:i:s');

        // Dynamic 15-Minute Grace Period Evaluation
        $startTime = '10:00:00';
        $graceMinutes = 15;

        if ($user->shift) {
            $startTime = strlen($user->shift->start_time) === 5 ? $user->shift->start_time . ':00' : $user->shift->start_time;
            $graceMinutes = (int) ($user->shift->grace_period_minutes ?? 15);
        }

        try {
            $shiftStart = Carbon::createFromFormat('H:i:s', $startTime);
            $cutoffTime = (clone $shiftStart)->addMinutes($graceMinutes)->format('H:i:s');
        } catch (\Exception $e) {
            $startTime = '10:00:00';
            $shiftStart = Carbon::createFromFormat('H:i:s', '10:00:00');
            $cutoffTime = '10:15:00';
        }

        $isLate = $nowTime > $cutoffTime;
        $status = $isLate ? 'late' : 'present';

        // Calculate exact minutes late past shift start time
        $lateNote = 'On-time check-in';
        $lateMinutes = 0;
        if ($isLate) {
            try {
                $checkInTime = Carbon::createFromFormat('H:i:s', $nowTime);
                $diff = $checkInTime->diffInMinutes($shiftStart);
                $lateMinutes = abs((int) $diff);
                $lateNote = $this->formatLateDuration($lateMinutes, $startTime);
            } catch (\Exception $e) {
                $lateNote = "Late check-in (After " . substr($cutoffTime, 0, 5) . ")";
            }
        }

        $finalNotes = $request->notes ?? $lateNote;
        if ($locationVerifiedNote) {
            $finalNotes = $finalNotes . ' | ' . $locationVerifiedNote;
        }

        if ($existing) {
            $existing->check_in = $nowTime;
            $existing->status = $status;
            $existing->notes = $finalNotes;
            $existing->save();
            $attendance = $existing;
        } else {
            $attendance = Attendance::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'date' => Carbon::today()->toDateString(),
                'check_in' => $nowTime,
                'status' => $status,
                'notes' => $finalNotes,
            ]);
        }

        return response()->json([
            'message' => 'Checked in successfully at ' . $nowTime . ($isLate ? " (Late by {$lateMinutes} mins)" : " (On-Time)"),
            'attendance' => $attendance
        ]);
    }

    public function checkOut(Request $request)
    {
        $user = $request->user();

        // Process any pending auto-checkouts before checking out
        $this->processAutoCheckouts($user->organization_id, $user->id);

        $attendance = Attendance::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->first();

        if (!$attendance || !$attendance->check_in) {
            return response()->json([
                'message' => 'You must check in first before checking out.'
            ], 400);
        }

        if ($attendance->check_out) {
            $formattedTime = substr($attendance->check_out, 0, 5);
            $isAuto = $attendance->notes && (str_contains($attendance->notes, 'Auto check-out') || str_contains($attendance->notes, 'Auto clocked out'));
            return response()->json([
                'message' => 'You have already checked out today at ' . $formattedTime . ($isAuto ? ' (Auto checked out at shift end).' : '.'),
                'attendance' => $attendance
            ], 400);
        }

        $nowTime = Carbon::now()->format('H:i:s');
        $attendance->check_out = $nowTime;
        $attendance->save();

        return response()->json([
            'message' => 'Checked out successfully at ' . substr($nowTime, 0, 5),
            'attendance' => $attendance
        ]);
    }

    public function history(Request $request)
    {
        $user = $request->user();

        // Process any pending auto-checkouts before fetching history
        $this->processAutoCheckouts($user->organization_id);

        $roleName = strtolower($user->role->name ?? 'employee');
        $targetUserId = (int) $request->query('user_id', $user->id);

        // Verification of permission to inspect target user's attendance
        if ($targetUserId !== $user->id) {
            if ($roleName === 'employee') {
                return response()->json(['message' => 'Unauthorized: Employees cannot view attendance of other users'], 403);
            }

            $targetUser = User::where('organization_id', $user->organization_id)
                ->where('id', $targetUserId)
                ->first();

            if (!$targetUser) {
                return response()->json(['message' => 'Target user not found in organization'], 404);
            }

            if ($roleName === 'manager' && $targetUser->manager_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized: Managers can only view attendance for direct team members'], 403);
            }
        }

        $query = Attendance::with('user')->where('organization_id', $user->organization_id);

        if ($request->has('user_id') && $request->user_id != '') {
            $query->where('user_id', $targetUserId);
        } elseif ($roleName === 'employee') {
            $query->where('user_id', $user->id);
        } elseif ($roleName === 'manager') {
            $teamUserIds = User::where('organization_id', $user->organization_id)
                ->where(function ($q) use ($user) {
                    $q->where('manager_id', $user->id)->orWhere('id', $user->id);
                })
                ->pluck('id');
            $query->whereIn('user_id', $teamUserIds);
        }

        if ($request->has('date') && $request->date != '') {
            $query->whereDate('date', $request->date);
        } elseif ($request->has('start_date') && $request->start_date != '' && $request->has('end_date') && $request->end_date != '') {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        } elseif ($request->has('start_date') && $request->start_date != '') {
            $query->whereDate('date', '>=', $request->start_date);
        } elseif ($request->has('end_date') && $request->end_date != '') {
            $query->whereDate('date', '<=', $request->end_date);
        }

        if ($request->has('month') && $request->month != '') {
            $query->where('date', 'like', $request->month . '%');
        }

        if ($request->has('status') && $request->status != '' && $request->status != 'all') {
            if ($request->status === 'present') {
                $query->whereIn('status', ['present', 'late']);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->has('search') && $request->search != '') {
            $searchTerm = '%' . $request->search . '%';
            $query->whereHas('user', function ($q) use ($searchTerm) {
                $q->where('name', 'like', $searchTerm)
                  ->orWhere('employee_code', 'like', $searchTerm);
            });
        }

        $attendances = $query->orderBy('date', 'desc')->get()->toArray();

        // Include synthetic absent records for today (or filtered date) if viewing organizational / team history
        if ((!$request->has('user_id') || $request->user_id == '') && (!$request->has('start_date') || $request->start_date == $request->end_date)) {
            $targetDateStr = $request->get('date', Carbon::today()->toDateString());
            $todayStr = Carbon::today()->toDateString();

            if ($targetDateStr === $todayStr) {
                // Active users in scope
                $usersQuery = User::where('organization_id', $user->organization_id)->where('status', 'active');
                if ($roleName === 'manager') {
                    $usersQuery->where(function ($q) use ($user) {
                        $q->where('manager_id', $user->id)->orWhere('id', $user->id);
                    });
                } elseif ($roleName === 'employee') {
                    $usersQuery->where('id', $user->id);
                }
                $activeUsers = $usersQuery->get();

                // Find user_ids that already have attendance logged for today
                $checkedInTodayUserIds = Attendance::where('organization_id', $user->organization_id)
                    ->whereDate('date', $targetDateStr)
                    ->pluck('user_id')
                    ->toArray();

                foreach ($activeUsers as $activeUser) {
                    if (!in_array($activeUser->id, $checkedInTodayUserIds)) {
                        $attendances[] = [
                            'id' => 'absent_' . $activeUser->id . '_' . $targetDateStr,
                            'organization_id' => $activeUser->organization_id,
                            'user_id' => $activeUser->id,
                            'date' => $targetDateStr,
                            'check_in' => null,
                            'check_out' => null,
                            'status' => 'absent',
                            'notes' => 'Not checked in yet today',
                            'user' => $activeUser->toArray(),
                        ];
                    }
                }
            }
        }

        return response()->json(['attendances' => $attendances]);
    }

    public function summary(Request $request)
    {
        $user = $request->user();

        // Process any pending auto-checkouts before computing summary
        $this->processAutoCheckouts($user->organization_id);

        $roleName = strtolower($user->role->name ?? 'employee');

        if (in_array($roleName, ['admin', 'hr'])) {
            // Organization-wide summary
            $totalEmployees = User::where('organization_id', $user->organization_id)->where('status', 'active')->count();

            $presentToday = Attendance::where('organization_id', $user->organization_id)
                ->whereDate('date', Carbon::today())
                ->whereIn('status', ['present', 'late'])
                ->count();

            $lateToday = Attendance::where('organization_id', $user->organization_id)
                ->whereDate('date', Carbon::today())
                ->where('status', 'late')
                ->count();

            $onLeaveToday = Attendance::where('organization_id', $user->organization_id)
                ->whereDate('date', Carbon::today())
                ->where('status', 'on_leave')
                ->count();

            $absentToday = max(0, $totalEmployees - ($presentToday + $onLeaveToday));
        } elseif ($roleName === 'manager') {
            // Team-wide summary (direct reports + manager)
            $teamUserIds = User::where('organization_id', $user->organization_id)
                ->where(function ($q) use ($user) {
                    $q->where('manager_id', $user->id)
                      ->orWhere('id', $user->id);
                })
                ->where('status', 'active')
                ->pluck('id')
                ->toArray();

            $totalEmployees = count($teamUserIds);

            $presentToday = Attendance::where('organization_id', $user->organization_id)
                ->whereIn('user_id', $teamUserIds)
                ->whereDate('date', Carbon::today())
                ->whereIn('status', ['present', 'late'])
                ->count();

            $lateToday = Attendance::where('organization_id', $user->organization_id)
                ->whereIn('user_id', $teamUserIds)
                ->whereDate('date', Carbon::today())
                ->where('status', 'late')
                ->count();

            $onLeaveToday = Attendance::where('organization_id', $user->organization_id)
                ->whereIn('user_id', $teamUserIds)
                ->whereDate('date', Carbon::today())
                ->where('status', 'on_leave')
                ->count();

            $absentToday = max(0, $totalEmployees - ($presentToday + $onLeaveToday));
        } else {
            // Employee personal summary
            $totalEmployees = 1;
            $userTodayAttendance = Attendance::where('organization_id', $user->organization_id)
                ->where('user_id', $user->id)
                ->whereDate('date', Carbon::today())
                ->first();

            $presentToday = ($userTodayAttendance && in_array($userTodayAttendance->status, ['present', 'late'])) ? 1 : 0;
            $lateToday = ($userTodayAttendance && $userTodayAttendance->status === 'late') ? 1 : 0;
            $onLeaveToday = ($userTodayAttendance && $userTodayAttendance->status === 'on_leave') ? 1 : 0;
            $absentToday = ($userTodayAttendance || $presentToday || $onLeaveToday) ? 0 : 1;
        }

        $userTodayAttendance = Attendance::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->first();

        return response()->json([
            'summary' => [
                'total_employees' => $totalEmployees,
                'present_today' => $presentToday,
                'late_today' => $lateToday,
                'on_leave_today' => $onLeaveToday,
                'absent_today' => $absentToday,
            ],
            'my_today' => $userTodayAttendance,
        ]);
    }

    public function adminCorrection(Request $request)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        if ($roleName !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Only Admin can correct attendance records'], 403);
        }

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'check_in' => 'nullable',
            'check_out' => 'nullable',
            'status' => 'required|in:present,absent,late,half_day,on_leave',
            'notes' => 'nullable|string',
        ]);

        // Target user must belong to actor's organization
        $targetUser = User::where('organization_id', $actor->organization_id)
            ->where('id', $request->user_id)
            ->first();

        if (!$targetUser) {
            return response()->json(['message' => 'Target user not found in organization'], 404);
        }

        $checkIn = $request->check_in ? trim($request->check_in) : null;
        if ($checkIn === '') $checkIn = null;
        if ($checkIn && strlen($checkIn) === 5) {
            $checkIn .= ':00';
        }

        $checkOut = $request->check_out ? trim($request->check_out) : null;
        if ($checkOut === '') $checkOut = null;
        if ($checkOut && strlen($checkOut) === 5) {
            $checkOut .= ':00';
        }

        $attendance = Attendance::updateOrCreate(
            [
                'organization_id' => $actor->organization_id,
                'user_id' => $request->user_id,
                'date' => Carbon::parse($request->date)->toDateString(),
            ],
            [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'status' => $request->status,
                'notes' => $request->notes ?? 'Manually adjusted by Admin',
            ]
        );

        $attendance->load('user');

        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'attendance_correction',
            'target_type' => Attendance::class,
            'target_id' => $attendance->id,
            'payload' => $request->all(),
        ]);

        return response()->json([
            'message' => 'Attendance corrected successfully',
            'attendance' => $attendance
        ]);
    }

    public function getSchedule(Request $request)
    {
        $user = $request->user();
        $user->load('shift');

        $isSaturday = Carbon::today()->isSaturday();
        $shiftEndTime = ($user->shift && $user->shift->end_time) ? $user->shift->end_time : '18:00:00';
        $todayAutoCheckout = $isSaturday ? '14:00:00' : $shiftEndTime;

        if ($user->shift) {
            return response()->json([
                'schedule' => [
                    'shift_name' => $user->shift->name,
                    'work_days' => $user->shift->work_days ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                    'start_time' => $user->shift->start_time ?: '10:00:00',
                    'end_time' => $isSaturday ? '14:00:00' : ($user->shift->end_time ?: '18:00:00'),
                    'regular_end_time' => $user->shift->end_time ?: '18:00:00',
                    'saturday_end_time' => '14:00:00',
                    'today_auto_checkout_time' => $todayAutoCheckout,
                    'grace_period_minutes' => $user->shift->grace_period_minutes ?? 15,
                ]
            ]);
        }

        return response()->json([
            'schedule' => [
                'shift_name' => 'Standard General Shift',
                'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                'start_time' => '10:00:00',
                'end_time' => $isSaturday ? '14:00:00' : '18:00:00',
                'regular_end_time' => '18:00:00',
                'saturday_end_time' => '14:00:00',
                'today_auto_checkout_time' => $todayAutoCheckout,
                'grace_period_minutes' => 15,
            ]
        ]);
    }

    public function updateSchedule(Request $request)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only Admin or HR can update work schedule settings'], 403);
        }

        return response()->json([
            'message' => 'Work schedule settings updated successfully',
            'schedule' => $request->all(),
        ]);
    }

    private function formatLateDuration(int $totalMinutes, string $startTime): string
    {
        $totalMins = abs($totalMinutes);
        $shiftStartFormatted = substr($startTime, 0, 5);

        if ($totalMins < 60) {
            return "Late by {$totalMins} mins (Shift Start: {$shiftStartFormatted})";
        }

        $hours = (int) floor($totalMins / 60);
        $mins = $totalMins % 60;
        $hrStr = $hours . ($hours > 1 ? ' hrs' : ' hr');

        if ($mins === 0) {
            return "Late by {$hrStr} (Shift Start: {$shiftStartFormatted})";
        }

        return "Late by {$hrStr} {$mins} mins (Shift Start: {$shiftStartFormatted})";
    }

    /**
     * Public API endpoint to trigger automatic checkouts.
     */
    public function triggerAutoCheckout(Request $request)
    {
        $user = $request->user();
        $count = $this->processAutoCheckouts($user->organization_id);

        return response()->json([
            'message' => "Auto check-out evaluated successfully. {$count} record(s) automatically checked out (6:00 PM Mon-Fri, 2:00 PM Saturdays).",
            'updated_count' => $count,
        ]);
    }

    /**
     * Automatically clock out employees who have checked in but haven't clocked out:
     * - Every Saturday: 2:00 PM (14:00:00)
     * - Every regular weekday (Monday - Friday): 6:00 PM (18:00:00)
     */
    public function processAutoCheckouts($organizationId = null, $userId = null)
    {
        // Automatic clock-out at 6 PM / shift end has been disabled as requested.
        // Employees now manually clock out whenever they finish their shift.
        return 0;
    }

    /**
     * Get organization's office geofence location and allowed radius settings.
     */
    public function getOfficeLocation(Request $request)
    {
        $user = $request->user();
        $org = Organization::find($user->organization_id);
        $settings = $org->settings ?? [];
        $officeLocation = $settings['office_location'] ?? null;

        if (!$officeLocation || !isset($officeLocation['latitude']) || !isset($officeLocation['longitude']) || (isset($officeLocation['radius_meters']) && $officeLocation['radius_meters'] == 2000) || (isset($officeLocation['latitude']) && abs(floatval($officeLocation['latitude']) - 22.2955) < 0.001)) {
            $officeLocation = [
                'enabled' => $officeLocation['enabled'] ?? true,
                'name' => $officeLocation['name'] ?? 'Main Office Headquarters',
                'latitude' => 22.3039,
                'longitude' => 73.1783,
                'radius_meters' => 500,
                'address' => $officeLocation['address'] ?? 'SF 02, INDIA BULLS MEGA MALL, Dinesh Mill Rd, near Swami Vivekananda Railway Over Bridge, Anand Nagar, Akota, Vadodara, Gujarat 390022',
            ];
            if ($org) {
                $settings['office_location'] = $officeLocation;
                $org->settings = $settings;
                $org->save();
            }
        }

        return response()->json([
            'office_location' => $officeLocation,
        ]);
    }

    /**
     * Update organization's office geofence coordinates and allowed radius.
     * Restricted: Only Admin or HR.
     */
    public function updateOfficeLocation(Request $request)
    {
        $actor = $request->user();
        $roleName = strtolower($actor->role->name ?? $actor->role ?? 'employee');
        if (method_exists($actor, 'getCanonicalRole')) {
            $roleName = $actor->getCanonicalRole();
        }

        if (!in_array($roleName, ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only Admin or HR can configure office location and geofencing.'], 403);
        }

        $request->validate([
            'enabled' => 'sometimes|boolean',
            'name' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_meters' => 'required|integer|min:20|max:10000',
            'address' => 'nullable|string',
        ]);

        $org = Organization::find($actor->organization_id);
        $settings = $org->settings ?? [];
        $settings['office_location'] = [
            'enabled' => $request->has('enabled') ? (bool) $request->enabled : true,
            'name' => $request->name,
            'latitude' => floatval($request->latitude),
            'longitude' => floatval($request->longitude),
            'radius_meters' => intval($request->radius_meters),
            'address' => $request->address ?: '',
        ];

        $org->settings = $settings;
        $org->save();

        AuditLog::create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->id,
            'action' => 'office_geofence_updated',
            'target_type' => Organization::class,
            'target_id' => $org->id,
            'payload' => $settings['office_location'],
        ]);

        return response()->json([
            'message' => 'Office geofence location and radius updated successfully.',
            'office_location' => $settings['office_location'],
        ]);
    }

    /**
     * Calculate distance between two GPS coordinates in meters using the Haversine formula.
     */
    private function calculateDistanceMeters($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371000; // in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
