<?php

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees an authenticated user always has a valid current company in the
 * session: one they actually belong to. Falls back to the user's first active
 * company (users attached to no company see nothing company-scoped).
 */
class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $companyIds = $user->companies()->where('is_active', true)->pluck('companies.id');
            $current = CurrentCompany::id();
            // 0 = «Все компании» (общий отчёт) — доступен бухгалтеру/админу с 2+ фирмами.
            $allAllowed = $current === 0
                && $user->hasAnyRole(['admin', 'financist'])
                && $companyIds->count() > 1;

            if (! $allAllowed && ! $companyIds->contains($current)) {
                $first = $companyIds->first();
                if ($first) {
                    CurrentCompany::set($first);
                } else {
                    $request->session()->forget('company_id');
                }
            }
        }

        return $next($request);
    }
}
