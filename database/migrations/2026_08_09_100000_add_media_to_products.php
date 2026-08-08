<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Медиа карточки каталога:
 *  - images (уже была) — фотогалерея товара: [{path, thumb, alt}];
 *  - texture_path — фото, которое 3D-сцены используют как ТЕКСТУРУ изделия
 *    (снимок поверхности плитки/бордюра сверху). Без него сцена рисует цвет;
 *  - model_path — необязательная GLB-модель: если загружена, конфигуратор
 *    показывает настоящую геометрию вместо процедурной.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('texture_path')->nullable()->after('images');
            $table->string('model_path')->nullable()->after('texture_path');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['texture_path', 'model_path']);
        });
    }
};
