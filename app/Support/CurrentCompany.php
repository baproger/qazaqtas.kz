<?php

namespace App\Support;

use App\Models\Company;

/**
 * The firm (BAIA / ASU) the user is currently working in. Chosen at login or
 * via the header switcher, kept in the session; every company-scoped query
 * (deals, workshop, finance, analytics, payroll) filters by this id.
 */
class CurrentCompany
{
    public static function id(): ?int
    {
        return session('company_id');
    }

    public static function get(): ?Company
    {
        $id = self::id();

        return $id ? Company::find($id) : null;
    }

    public static function set(int $id): void
    {
        session(['company_id' => $id]);
    }
}
