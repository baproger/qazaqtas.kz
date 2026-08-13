<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Облегчённая копия снимка категории: WebP держит альфу и весит меньше PNG. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('webp')->nullable()->after('thumb');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('webp');
        });
    }
};
