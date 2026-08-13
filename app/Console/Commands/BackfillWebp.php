<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Дозаписывает WebP-копии рядом со снимками, загруженными до того, как
 * MediaService научился их делать. Исходные файлы не трогает.
 */
class BackfillWebp extends Command
{
    protected $signature = 'media:webp {--dry : только показать, что будет сделано}';

    protected $description = 'Создать WebP-копии для уже загруженных снимков';

    public function handle(): int
    {
        if (! function_exists('imagewebp')) {
            $this->error('В этой сборке PHP нет поддержки WebP (расширение gd без webp).');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $made = 0;
        $saved = 0;

        foreach (ProductCategory::whereNotNull('image')->get() as $category) {
            if ($category->webp) {
                continue;
            }
            $result = $this->convert($category->image, $dry);
            if (! $result) {
                continue;
            }
            [$url, $delta] = $result;
            $made++;
            $saved += $delta;
            if (! $dry) {
                $category->update(['webp' => $url]);
            }
            $this->line("категория «{$category->name}» → {$url}");
        }

        foreach (Product::whereJsonLength('images', '>', 0)->get() as $product) {
            $images = $product->images;
            $changed = false;

            foreach ($images as $i => $image) {
                if (! empty($image['webp']) || empty($image['path'])) {
                    continue;
                }
                $result = $this->convert($image['path'], $dry);
                if (! $result) {
                    continue;
                }
                [$url, $delta] = $result;
                $images[$i]['webp'] = $url;
                $changed = true;
                $made++;
                $saved += $delta;
                $this->line("позиция «{$product->name}» → {$url}");
            }

            if ($changed && ! $dry) {
                $product->update(['images' => $images]);
            }
        }

        if (! $dry && $made) {
            CatalogService::flushCache();
        }

        $this->info(sprintf(
            '%s копий: %d, экономия: %.1f МБ',
            $dry ? 'Будет создано' : 'Создано',
            $made,
            $saved / 1024 / 1024,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: int}|null адрес копии и сколько байт сэкономлено
     */
    private function convert(string $url, bool $dry): ?array
    {
        $path = ltrim(str_replace('/storage/', '', $url), '/');
        $disk = Storage::disk('public');

        if ($path === $url || ! $disk->exists($path)) {
            return null;
        }

        $image = @imagecreatefromstring($disk->get($path));
        if (! $image) {
            return null;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, 82);
        $data = (string) ob_get_clean();

        if (! $ok || $data === '') {
            return null;
        }

        $target = preg_replace('/\.[a-z0-9]+$/i', '.webp', $path);
        $delta = max(0, $disk->size($path) - strlen($data));

        if (! $dry) {
            $disk->put($target, $data);
        }

        return [Storage::url($target), $delta];
    }
}
