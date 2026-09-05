<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Seo;

/**
 * SEO карточки товара: заголовок, описание, ключевые слова — ru и kk.
 *
 * Два пути с одинаковой формой ответа. С ключом ANTHROPIC_API_KEY тексты
 * пишет Claude (официальный PHP SDK, кнопка «Сгенерировать ИИ»); без ключа
 * работает шаблонный генератор из данных карточки — он же заполняет SEO
 * автоматически при создании товара, чтобы ни одна страница не выходила
 * на витрину с пустыми метатегами.
 */
class SeoAiService
{
    /** @return array{ru: array<string,string>, kk: array<string,string>, source: string} */
    public function generate(Product $product): array
    {
        if (config('services.anthropic.key') && class_exists(\Anthropic\Client::class)) {
            try {
                return $this->viaClaude($product) + ['source' => 'ai'];
            } catch (\Throwable $e) {
                report($e); // ИИ недоступен — кнопку спасает шаблон
            }
        }

        return $this->template($product) + ['source' => 'template'];
    }

    /** Автозаполнение при создании товара: мгновенно, без внешних запросов. */
    public function fillIfEmpty(Product $product): void
    {
        if ($product->seoMeta()->exists()) {
            return;
        }

        $g = $this->template($product);
        $product->seoMeta()->create([
            'title' => $g['ru']['title'],
            'description' => $g['ru']['description'],
            'keywords' => $g['ru']['keywords'],
            'title_kk' => $g['kk']['title'],
            'description_kk' => $g['kk']['description'],
            'keywords_kk' => $g['kk']['keywords'],
        ]);
    }

    /** @return array{ru: array<string,string>, kk: array<string,string>} */
    public function template(Product $product): array
    {
        $name = (string) $product->name;
        $nameKk = (string) (optional($product->translations->firstWhere('locale', 'kk'))->name ?: $name);
        $category = (string) ($product->category?->name ?? '');
        $size = (string) (($product->specs ?? [])['size'] ?? '');
        $price = number_format((float) $product->price, 0, ',', ' ');
        $unit = (string) $product->unit;

        return [
            'ru' => [
                'title' => Seo::text("{$name} — купить от {$price} ₸/{$unit} | QAZAQ TAS", 70),
                'description' => Seo::text(
                    "{$name} из мраморного композита от завода QAZAQ TAS."
                    .($size ? " Размер {$size}." : '')
                    ." Морозостойкость F200, класс прочности B30. Цена от {$price} ₸/{$unit}, доставка из Шымкента, Алматы и Тараза.",
                    160,
                ),
                'keywords' => mb_strtolower(trim("{$name}, {$category}, мраморный композит, купить в казахстане, qazaq tas", ', ')),
            ],
            'kk' => [
                'title' => Seo::text("{$nameKk} — {$price} ₸/{$unit} бастап сатып алу | QAZAQ TAS", 70),
                'description' => Seo::text(
                    "{$nameKk} — QAZAQ TAS зауытының мәрмәр композиті."
                    .($size ? " Өлшемі {$size}." : '')
                    ." Аязға төзімділік F200, беріктік класы B30. Бағасы {$price} ₸/{$unit} бастап, Шымкент, Алматы және Тараздан жеткізу.",
                    160,
                ),
                'keywords' => mb_strtolower(trim("{$nameKk}, мәрмәр композиті, қазақстанда сатып алу, qazaq tas", ', ')),
            ],
        ];
    }

    /** @return array{ru: array<string,string>, kk: array<string,string>} */
    private function viaClaude(Product $product): array
    {
        $client = new \Anthropic\Client(apiKey: (string) config('services.anthropic.key'));

        $facts = json_encode([
            'name_ru' => $product->name,
            'name_kk' => optional($product->translations->firstWhere('locale', 'kk'))->name,
            'category' => $product->category?->name,
            'specs' => $product->specs,
            'price' => (float) $product->price,
            'unit' => $product->unit,
            'short_description' => $product->short_description,
            'brand' => 'QAZAQ TAS — завод изделий из мраморного композита, Казахстан (Шымкент, Алматы, Тараз)',
        ], JSON_UNESCAPED_UNICODE);

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 2000,
            system: 'Ты SEO-редактор витрины завода QAZAQ TAS. По данным товара составь метатеги на двух языках. '
                .'Требования: title до 70 символов с брендом; description до 160 символов, продающий и конкретный, '
                .'без кавычек-ёлочек в начале; keywords — 5–7 фраз через запятую, строчными. Казахский — грамотный, '
                .'естественный (не калька). Ответь ТОЛЬКО валидным JSON без пояснений и без markdown: '
                .'{"ru":{"title":"","description":"","keywords":""},"kk":{"title":"","description":"","keywords":""}}',
            messages: [['role' => 'user', 'content' => (string) $facts]],
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }

        // Модель просили отвечать голым JSON, но страхуемся от ```-обёртки.
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $data = json_decode($text, true, 8, JSON_THROW_ON_ERROR);

        foreach (['ru', 'kk'] as $loc) {
            foreach (['title', 'description', 'keywords'] as $key) {
                if (! is_string($data[$loc][$key] ?? null) || $data[$loc][$key] === '') {
                    throw new \RuntimeException("SEO-ответ ИИ без поля {$loc}.{$key}");
                }
            }
            $data[$loc]['title'] = Seo::text($data[$loc]['title'], 70);
            $data[$loc]['description'] = Seo::text($data[$loc]['description'], 160);
            $data[$loc]['keywords'] = Seo::text($data[$loc]['keywords'], 300);
        }

        return ['ru' => $data['ru'], 'kk' => $data['kk']];
    }
}
