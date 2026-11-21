<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    /**
     * Get all roles
     */
    public function index(Request $request)
    {
        Role::ensureStandardRoles();
        $roles = Role::orderBy('id', 'asc')->get();
        return response()->json([
            'roles' => $roles
        ]);
    }

    /**
     * Create a new role
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'display_name' => 'required|string|max:100',
            'name' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'permissions' => 'nullable|array',
        ]);

        $displayName = trim($validated['display_name']);
        
        // Auto-generate system key if not provided or empty
        $systemKey = !empty($validated['name']) 
            ? Str::slug($validated['name'], '_') 
            : Str::slug($displayName, '_');

        // Check if role name already exists
        if (Role::where('name', $systemKey)->exists()) {
            return response()->json([
                'message' => "A role with system key '{$systemKey}' already exists."
            ], 422);
        }

        $role = Role::create([
            'name' => $systemKey,
            'display_name' => $displayName,
            'description' => $validated['description'] ?? "Custom role: {$displayName}",
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return response()->json([
            'message' => "Role '{$displayName}' created successfully!",
            'role' => $role
        ], 201);
    }

    /**
     * Update an existing role (especially permissions and description)
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $standardRoles = ['admin', 'hr', 'manager', 'team_leader', 'employee'];
        $isStandard = in_array(strtolower($role->name), $standardRoles);

        $validated = $request->validate([
            'display_name' => 'sometimes|required|string|max:100',
            'name' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'permissions' => 'nullable|array',
        ]);

        if (isset($validated['display_name'])) {
            $role->display_name = trim($validated['display_name']);
        }
        
        // Allow updating system key (code) for custom roles
        if (!empty($validated['name']) && !$isStandard) {
            $newKey = Str::slug($validated['name'], '_');
            // If changed, ensure uniqueness
            if ($newKey !== $role->name) {
                if (Role::where('name', $newKey)->where('id', '!=', $id)->exists()) {
                    return response()->json([
                        'message' => "A role with system key '{$newKey}' already exists."
                    ], 422);
                }
                $role->name = $newKey;
            }
        }

        if (isset($validated['description'])) {
            $role->description = $validated['description'];
        }
        if (isset($validated['permissions'])) {
            $role->permissions = $validated['permissions'];
        }

        $role->save();

        return response()->json([
            'message' => "Role '{$role->display_name}' updated successfully!",
            'role' => $role
        ]);
    }

    /**
     * Delete a role (only custom roles can be deleted, standard 5 are protected)
     */
    public function destroy(Request $request, $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $standardRoles = ['admin', 'hr', 'manager', 'team_leader', 'employee'];
        if (in_array(strtolower($role->name), $standardRoles)) {
            return response()->json([
                'message' => "Standard system role '{$role->display_name}' cannot be deleted."
            ], 403);
        }

        $role->delete();

        return response()->json([
            'message' => "Role '{$role->display_name}' deleted successfully!"
        ]);
    }
}
