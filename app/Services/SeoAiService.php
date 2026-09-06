<?php

namespace App\Services;

use App\Models\Product;
use App\Support\AiKey;
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
        if (AiKey::isSet() && class_exists(\Anthropic\Client::class)) {
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

            'title_kk' => $g['kk']['title'],
            'description_kk' => $g['kk']['description'],

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
                'keywords' => '', // keywords не генерируем — только руками
            ],
            'kk' => [
                'title' => Seo::text("{$nameKk} — {$price} ₸/{$unit} бастап сатып алу | QAZAQ TAS", 70),
                'description' => Seo::text(
                    "{$nameKk} — QAZAQ TAS зауытының мәрмәр композиті."
                    .($size ? " Өлшемі {$size}." : '')
                    ." Аязға төзімділік F200, беріктік класы B30. Бағасы {$price} ₸/{$unit} бастап, Шымкент, Алматы және Тараздан жеткізу.",
                    160,
                ),
                'keywords' => '',
            ],
        ];
    }

    /**
     * Перевод карточки товара на обе локали витрины.
     *
     * В отличие от SEO, перевода «по шаблону» не бывает — без ключа ИИ
     * честно отказываемся, кнопка в ERP покажет причину.
     *
     * @param  array{name: string, short_description: ?string, description: ?string, specs: array<string,mixed>, colors: array<int,array{name:string,hex:string}>}  $base
     * @return array{kk: array<string,mixed>, ru: array<string,mixed>}
     */
    public function translations(array $base): array
    {
        if (AiKey::isSet() && class_exists(\Anthropic\Client::class)) {
            try {
                return $this->translationsViaClaude($base) + ['source' => 'ai'];
            } catch (\Throwable $e) {
                report($e); // ИИ недоступен — словарь и шаблон спасают кнопку
            }
        }

        return $this->templateTranslations($base) + ['source' => 'template'];
    }

    /**
     * Бесплатный перевод без ИИ: ru — базовые поля как есть, kk — словарь
     * домена (цвета, материалы, категории) плюс стандартное описание из
     * данных карточки. Числа, размеры и hex не трогаются.
     *
     * @return array{kk: array<string,mixed>, ru: array<string,mixed>}
     */
    public function templateTranslations(array $base): array
    {
        $name = (string) ($base['name'] ?? '');

        // Описания обоих языков собираем ОДНИМ шаблоном из данных карточки:
        // русская и казахская версии обязаны совпадать по смыслу, а не жить
        // каждая своей жизнью (ru раньше просто копировал старую заглушку).
        $described = $this->templateDescribe($base);

        $ru = [
            'name' => $name,
            'short_description' => $described['ru']['short_description'],
            'description' => $described['ru']['description'],
            'specs' => (array) ($base['specs'] ?? []),
            'colors' => array_values((array) ($base['colors'] ?? [])),
        ];

        $kk = [
            'name' => $name, // артикулы и размеры в названии универсальны
            'short_description' => $described['kk']['short_description'],
            'description' => $described['kk']['description'],
            'specs' => array_map(fn ($v) => is_string($v) ? self::kkWords($v) : $v, $ru['specs']),
            'colors' => array_map(fn ($c) => ['name' => mb_ucfirst(self::kkWords((string) ($c['name'] ?? ''))), 'hex' => $c['hex'] ?? ''], $ru['colors']),
        ];

        return ['kk' => $kk, 'ru' => $ru];
    }

    /**
     * Уникальное описание карточки на двух языках из данных товара.
     *
     * Владелец заметил, что все карточки вышли с одной заглушкой. Теперь
     * описание собирается из конкретики: категория, размер, морозостойкость,
     * палитра. С ключом ИИ текст пишет Claude; без ключа — шаблон, который
     * всё равно различается между товарами, потому что вплетает их данные.
     *
     * @return array{ru: array{short_description:string, description:string}, kk: array{short_description:string, description:string}, source: string}
     */
    public function describe(array $base): array
    {
        if (AiKey::isSet() && class_exists(\Anthropic\Client::class)) {
            try {
                return $this->describeViaClaude($base) + ['source' => 'ai'];
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->templateDescribe($base) + ['source' => 'template'];
    }

    /** @return array{ru: array<string,string>, kk: array<string,string>} */
    public function templateDescribe(array $base): array
    {
        $name = (string) ($base['name'] ?? '');
        $category = (string) ($base['category'] ?? '');
        $specs = (array) ($base['specs'] ?? []);
        $size = (string) ($specs['size'] ?? '');
        $frost = (string) ($specs['frost'] ?? '');
        $strength = (string) ($specs['strength'] ?? '');
        $colors = count((array) ($base['colors'] ?? []));

        $catRu = self::categoryPhrase($category, [
            'скам' => 'уличная скамья для парков, набережных и дворов',
            'плитк' => 'тротуарная плитка для дорожек, дворов и площадей',
            'бордюр' => 'бордюр для дорожек, газонов и проезжей части',
            'вазон' => 'уличный вазон для озеленения города',
            'урн' => 'уличная урна для парков и входных групп',
            'ступен' => 'ступени и облицовка входных групп',
        ], 'изделие для благоустройства');
        $catKk = self::categoryPhrase($category, [
            'скам' => 'саябақтар мен аулаларға арналған көше орындығы',
            'плитк' => 'жолдар мен алаңдарға арналған тротуар тақтасы',
            'бордюр' => 'жолдар мен газондарға арналған бордюр',
            'вазон' => 'қаланы көгалдандыруға арналған вазон',
            'урн' => 'саябақтарға арналған қоқыс жәшігі',
            'ступен' => 'кіреберіс топтарына арналған саты мен қаптама',
        ], 'абаттандыруға арналған бұйым');

        $ru = "{$name} — {$catRu} из мраморного композита QAZAQ TAS.";
        $kk = "{$name} — QAZAQ TAS мәрмәр композитінен жасалған {$catKk}.";
        if ($size) {
            $ru .= " Размер {$size}.";
            $kk .= " Өлшемі {$size}.";
        }
        if ($frost || $strength) {
            $ru .= ' '.trim(($frost ? "Морозостойкость {$frost}" : '').($frost && $strength ? ', ' : '').($strength ? "класс прочности {$strength}" : '')).'.';
            $kk .= ' '.trim(($frost ? "Аязға төзімділік {$frost}" : '').($frost && $strength ? ', ' : '').($strength ? "беріктік класы {$strength}" : '')).'.';
        }
        if ($colors > 1) {
            $ru .= " {$colors} оттенков, цвет сквозной — не стирается и не выгорает.";
            $kk .= " {$colors} реңк, түсі сіңірілген — өшпейді және оңбайды.";
        }
        $ru .= ' Собственное производство в Шымкенте, Алматы и Таразе, доставка по всему Казахстану.';
        $kk .= ' Шымкент, Алматы және Тараздағы өз өндірісіміз, Қазақстан бойынша жеткізу.';

        return [
            'ru' => ['short_description' => trim(($size ? "{$size} · " : '').'мраморный композит'), 'description' => $ru],
            'kk' => ['short_description' => trim(($size ? "{$size} · " : '').'мәрмәр композиті'), 'description' => $kk],
        ];
    }

    /** Фраза категории по подстроке её названия. */
    private static function categoryPhrase(string $category, array $map, string $default): string
    {
        $needle = mb_strtolower($category);
        foreach ($map as $part => $phrase) {
            if ($needle !== '' && str_contains($needle, $part)) {
                return $phrase;
            }
        }

        return $default;
    }

    /** @return array{ru: array<string,string>, kk: array<string,string>} */
    private function describeViaClaude(array $base): array
    {
        $client = new \Anthropic\Client(apiKey: (string) AiKey::get());

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 3000,
            system: 'Ты редактор витрины завода QAZAQ TAS (изделия из мраморного композита, Казахстан: Шымкент, Алматы, Тараз). '
                .'По данным товара напиши УНИКАЛЬНОЕ описание карточки: 3–4 предложения, конкретика из характеристик '
                .'(размер, морозостойкость, прочность, палитра), назначение по категории, без воды и превосходных степеней. '
                .'Русская и казахская версии должны совпадать по смыслу; казахский — грамотный и естественный, не калька. '
                .'short_description — одна строка вида «размер · материал». '
                .'Ответь ТОЛЬКО валидным JSON: {"ru":{"short_description":"","description":""},"kk":{"short_description":"","description":""}}',
            messages: [['role' => 'user', 'content' => (string) json_encode($base, JSON_UNESCAPED_UNICODE)]],
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $data = json_decode($text, true, 8, JSON_THROW_ON_ERROR);

        foreach (['ru', 'kk'] as $loc) {
            foreach (['short_description', 'description'] as $key) {
                if (! is_string($data[$loc][$key] ?? null) || $data[$loc][$key] === '') {
                    throw new \RuntimeException("Описание ИИ без поля {$loc}.{$key}");
                }
            }
        }

        return ['ru' => $data['ru'], 'kk' => $data['kk']];
    }

    /** Словарь домена: длинные фразы раньше коротких, регистр не важен. */
    private static function kkWords(string $text): string
    {
        static $dict = [
            'мраморный композит' => 'мәрмәр композиті',
            'тротуарная плитка' => 'тротуар тақтасы',
            'мрамор белый' => 'ақ мәрмәр',
            'серый графит' => 'сұр графит',
            'песочный' => 'құмды',
            'антрацит' => 'антрацит',
            'терракота' => 'терракота',
            'зелёный' => 'жасыл',
            'зеленый' => 'жасыл',
            'красный' => 'қызыл',
            'чёрный' => 'қара',
            'черный' => 'қара',
            'белый' => 'ақ',
            'серый' => 'сұр',
            'коричневый' => 'қоңыр',
            'бордюр' => 'бордюр',
            'вазон' => 'вазон',
            'скамья' => 'орындық',
            'урна' => 'қоқыс жәшігі',
            'ступень' => 'саты',
        ];

        foreach ($dict as $ruWord => $kkWord) {
            $text = preg_replace('/'.preg_quote($ruWord, '/').'/iu', $kkWord, $text);
        }

        return $text;
    }

    /** @return array{kk: array<string,mixed>, ru: array<string,mixed>} */
    private function translationsViaClaude(array $base): array
    {
        $client = new \Anthropic\Client(apiKey: (string) AiKey::get());

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 4000,
            system: 'Ты переводчик витрины завода QAZAQ TAS (изделия из мраморного композита). '
                .'Переведи поля карточки товара на казахский (kk) и русский (ru). Требования: '
                .'kk — грамотный, естественный казахский, не калька; ru — литературный русский; '
                .'если поле уже на целевом языке — верни его отредактированную копию. '
                .'specs: ключи оставить ТЕ ЖЕ, переводить только значения; числа, размеры и единицы не менять. '
                .'colors: переводить только name, hex не менять, порядок и количество сохранить. '
                .'Ответь ТОЛЬКО валидным JSON без пояснений: '
                .'{"kk":{"name":"","short_description":"","description":"","specs":{},"colors":[{"name":"","hex":""}]},"ru":{...то же...}}',
            messages: [['role' => 'user', 'content' => (string) json_encode($base, JSON_UNESCAPED_UNICODE)]],
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $data = json_decode($text, true, 16, JSON_THROW_ON_ERROR);

        foreach (['kk', 'ru'] as $loc) {
            if (! is_array($data[$loc] ?? null)) {
                throw new \RuntimeException("Перевод без локали {$loc}");
            }
            $data[$loc]['specs'] = is_array($data[$loc]['specs'] ?? null) ? $data[$loc]['specs'] : [];
            // Гарантия «цвет тот же»: hex всегда из исходной палитры.
            $data[$loc]['colors'] = collect($base['colors'] ?? [])->values()->map(fn ($c, $i) => [
                'name' => (string) ($data[$loc]['colors'][$i]['name'] ?? $c['name']),
                'hex' => $c['hex'],
            ])->all();
        }

        return ['kk' => $data['kk'], 'ru' => $data['ru']];
    }

    /** @return array{ru: array<string,string>, kk: array<string,string>} */
    private function viaClaude(Product $product): array
    {
        $client = new \Anthropic\Client(apiKey: (string) AiKey::get());

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
                .'без кавычек-ёлочек в начале. Казахский — грамотный, '
                .'естественный (не калька). Ответь ТОЛЬКО валидным JSON без пояснений и без markdown: '
                .'{"ru":{"title":"","description":""},"kk":{"title":"","description":""}}',
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
            foreach (['title', 'description'] as $key) {
                if (! is_string($data[$loc][$key] ?? null) || $data[$loc][$key] === '') {
                    throw new \RuntimeException("SEO-ответ ИИ без поля {$loc}.{$key}");
                }
            }
            $data[$loc]['title'] = Seo::text($data[$loc]['title'], 70);
            $data[$loc]['description'] = Seo::text($data[$loc]['description'], 160);
            $data[$loc]['keywords'] = ''; // keywords не генерируем — только руками
        }

        return ['ru' => $data['ru'], 'kk' => $data['kk']];
    }
}
