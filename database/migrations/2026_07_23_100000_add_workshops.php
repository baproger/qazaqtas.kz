<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цеха внутри фирмы: у каждого цеха своя воронка этапов (project_stages.workshop)
 * и свой ТВ-экран, а заказ знает, в каком цехе он делается (projects.workshop).
 * У QAZAQ TAS производство одно (workshop = null) — выбор цеха не показывается;
 * второй цех добавляется в Настройки → Этапы без изменений в коде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_stages', fn (Blueprint $t) => $t->string('workshop', 100)->nullable()->after('company_id')->index());
        Schema::table('projects', fn (Blueprint $t) => $t->string('workshop', 100)->nullable()->after('project_stage_id'));
    }

    public function down(): void
    {
        Schema::table('project_stages', fn (Blueprint $t) => $t->dropColumn('workshop'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('workshop'));
    }
};
