<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Модуль «Услуги»: партнёры размещают услуги, ассистент модерирует,
 * одобренные видны в публичном каталоге. Плюс полиморфные SEO-метаданные
 * для любых страниц (ручные перекрывают автогенерацию).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('photo', 500)->nullable();        // веб-версия (public)
            $table->string('photo_webp', 500)->nullable();
            $table->string('photo_thumb', 500)->nullable();
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'category_id']);
            $table->index('partner_id');
        });

        // SEO для любых страниц: полиморфно, ручное перекрывает автогенерацию.
        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->morphs('seoable');
            $table->string('title')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('og_image', 500)->nullable();
            $table->timestamps();
            $table->unique(['seoable_type', 'seoable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
