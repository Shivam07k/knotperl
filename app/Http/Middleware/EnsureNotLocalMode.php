<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureNotLocalMode
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (config('cms.local_mode')) {
            return redirect()->route('dashboard')->with('error', 'not available in local mode');
        }

        return $next($request);
    }
}
