<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectNumberService
{
    /**
     * Generate a unique project number in the format PRJ-{year}-{sequence}.
     */
    public function generate(): string
    {
        $year = now()->year;
        $prefix = "PRJ-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $last = Project::withTrashed()
                ->where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('number');

            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
