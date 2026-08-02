<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('order')->default(0)->index();
            $table->string('color', 20)->default('#6B7280');
            $table->json('checklist')->nullable();
            $table->string('type')->default('project')->index();
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('project_stage_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_stage_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5)->index();
            $table->string('name');
            $table->unique(['project_stage_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stage_translations');
        Schema::dropIfExists('project_stages');
    }
};
