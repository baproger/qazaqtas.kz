<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * У каждой фирмы своя воронка сделок: deal_stages.company_id
     * (null = общий этап, легаси/тестовые базы). Существующие этапы уходят
     * первой фирме — при одной компании это вся воронка QAZAQ TAS.
     */
    public function up(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $companyId = DB::table('companies')->orderBy('id')->value('id');
        if ($companyId) {
            DB::table('deal_stages')->where('type', 'sale')->whereNull('company_id')
                ->update(['company_id' => $companyId]);
        }
    }

    public function down(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
