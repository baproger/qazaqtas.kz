<?php

namespace Tests\Feature;

use App\Support\ReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Кеш тяжёлых отчётов: в кеш и наружу уходят ТОЛЬКО чистые массивы.
 *
 * Регрессия 31.08.2026: build() возвращал Laravel-Collection, сериализация
 * объекта содержит NUL-байты, запись в БД-кеше портилась — и Аналитика
 * открывалась белым экраном с мусором вместо данных.
 */
class ReportCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_collections_are_flattened_to_arrays(): void
    {
        $request = Request::create('/analytics');

        $data = ReportCache::remember($request, 'test-report', fn () => [
            'rows' => collect([['income' => 100, 'expense' => 40]]),
            'nested' => ['deep' => collect(['a' => 1])],
            'plain' => 7,
        ]);

        $this->assertIsArray($data['rows']);
        $this->assertSame(100, $data['rows'][0]['income']);
        $this->assertIsArray($data['nested']['deep']);
        $this->assertSame(7, $data['plain']);
        // Ни одного объекта на всей глубине — то, что и хранится в кеше.
        $this->assertStringNotContainsString('O:', serialize($data)[0]);
    }

    public function test_second_call_serves_from_cache(): void
    {
        $request = Request::create('/analytics');
        $calls = 0;
        $build = function () use (&$calls) { $calls++; return ['n' => $calls]; };

        $first = ReportCache::remember($request, 'test-report', $build);
        $second = ReportCache::remember($request, 'test-report', $build);

        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);
    }

    public function test_bump_invalidates_all_reports(): void
    {
        $request = Request::create('/analytics');
        $calls = 0;
        $build = function () use (&$calls) { $calls++; return ['n' => $calls]; };

        ReportCache::remember($request, 'test-report', $build);
        ReportCache::bump();
        ReportCache::remember($request, 'test-report', $build);

        $this->assertSame(2, $calls);
    }
}
