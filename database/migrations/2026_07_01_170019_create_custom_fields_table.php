<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index(); // deal | project | task
            $table->string('name');
            $table->string('type'); // text,number,date,boolean,select,radio,file,email,phone,url
            $table->boolean('required')->default(false);
            $table->boolean('unique')->default(false);
            $table->json('options')->nullable(); // for select/radio
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('custom_field_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5)->index();
            $table->string('name');
            $table->unique(['custom_field_id', 'locale']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type')->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['custom_field_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_translations');
        Schema::dropIfExists('custom_fields');
    }
};
