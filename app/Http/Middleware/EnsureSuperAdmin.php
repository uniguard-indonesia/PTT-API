<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check() || !Auth::user()->isSuperAdmin()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(
                    [
                        "status" => "error",
                        "message" =>
                            "Unauthorized. Super Admin access required.",
                    ],
                    403,
                );
            }
            abort(403, "Unauthorized. Super Admin access required.");
        }

        return $next($request);
    }
}
