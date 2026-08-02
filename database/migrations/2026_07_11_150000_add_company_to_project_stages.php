<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Цех у каждой фирмы свой: project_stages получает company_id
     * (null = общий этап, легаси/тесты). Существующие этапы цеха уходят
     * первой фирме — при одной компании это производство QAZAQ TAS.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('project_stages', 'company_id')) {
            Schema::table('project_stages', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
        }

        $companyId = DB::table('companies')->orderBy('id')->value('id');
        if ($companyId) {
            DB::table('project_stages')->whereNull('company_id')->update(['company_id' => $companyId]);
        }
    }

    public function down(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
