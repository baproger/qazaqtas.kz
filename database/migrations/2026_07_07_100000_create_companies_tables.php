<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Code is the deal-number prefix: BAIA-001, ASU-001.
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['company_id', 'user_id']);
            $table->timestamps();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Bootstrap the two firms; existing deals were all created under BAIA.
        $now = now();
        DB::table('companies')->insert([
            ['name' => 'BAIA', 'code' => 'BAIA', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ASU', 'code' => 'ASU', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $baiaId = DB::table('companies')->where('code', 'BAIA')->value('id');
        DB::table('deals')->update(['company_id' => $baiaId]);

        // Attach every existing user to both companies so nobody loses access;
        // admins can narrow memberships later on the Сотрудники page.
        $companyIds = DB::table('companies')->pluck('id');
        $rows = [];
        foreach (DB::table('users')->pluck('id') as $userId) {
            foreach ($companyIds as $companyId) {
                $rows[] = ['company_id' => $companyId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows) {
            DB::table('company_user')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
