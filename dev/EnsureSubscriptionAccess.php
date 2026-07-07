<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSubscriptionAccess
{
    public function handle(Request $request, Closure $next)
    {
        require_once base_path('system_monitoring/license.php');

        if ($request->is('subscription') || $request->is('subscription/*')) {
            return $next($request);
        }

        $status = system_monitoring_license_status();
        if (! ($status['redirect'] ?? false)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required.',
                'reason' => $status['reason'] ?? 'missing_license',
            ], 403);
        }

        return redirect('/subscription/');
    }
}

