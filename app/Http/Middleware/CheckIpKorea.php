<?php

namespace App\Http\Middleware;

use Closure;

class CheckIpKorea
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next, $scope = 'default')
    {
        if ($scope == 'default') {
            $allowed_ips = ['127.0.0.1','195.158.16.9'];
        }
        elseif ($scope == 'check-ip-korea') {
            $allowed_ips = [
                '58.151.21.4',
                '195.158.16.9'
            ];
        }

        if (in_array($_SERVER['HTTP_X_FORWARDED_FOR'], $allowed_ips)) {
            return $next($request);
        }
        echo $_SERVER['HTTP_X_FORWARDED_FOR'] . " - [IP ADDRESS] permission denied | CheckAuth";
        abort(403);
    }
}
