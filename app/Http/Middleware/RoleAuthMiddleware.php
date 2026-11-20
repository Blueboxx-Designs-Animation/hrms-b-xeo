<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleAuthMiddleware
{
    /**
     * Handle an incoming request and enforce server-side role-based access control.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userRole = $user->getCanonicalRole();

        // Admin override: admins are permitted for all role-restricted routes
        if ($userRole === 'admin') {
            return $next($request);
        }

        // Custom role check: if the user has custom role and permission exists
        $userRoleModel = $user->role;
        $userPermissions = $userRoleModel ? ($userRoleModel->permissions ?? []) : [];
        if (in_array('all', $userPermissions)) {
            return $next($request);
        }

        if (!empty($roles)) {
            $allowedRoles = array_map('strtolower', $roles);
            
            // Check direct canonical role match
            if (in_array($userRole, $allowedRoles)) {
                return $next($request);
            }

            // Check if actual role name (e.g. custom key) matches
            $rawRoleName = strtolower($userRoleModel->name ?? '');
            if (in_array($rawRoleName, $allowedRoles)) {
                return $next($request);
            }

            // Route uri to module mapping check
            $path = $request->path(); // e.g. api/invoices, api/clients
            $module = explode('/', str_replace('api/', '', $path))[0] ?? '';
            
            // Allow if module matches user permissions
            if (!empty($module) && (in_array($module, $userPermissions) || ($module === 'clients' && in_array('invoices', $userPermissions)))) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Unauthorized: Insufficient role permissions for this endpoint'
            ], 403);
        }

        return $next($request);
    }
}
