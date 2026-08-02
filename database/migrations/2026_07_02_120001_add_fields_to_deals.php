<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('client_name');
            $table->string('lot_number')->nullable()->after('company_name');
            $table->text('note')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'lot_number', 'note']);
        });
    }
};
