# Деплой на сервер (Plesk + Git)

Схема: `я делаю git push → GitHub → Plesk тянет автоматически → сайт обновляется`.
Собранный фронтенд (`public/build`) лежит в репозитории, поэтому **Node на сервере не нужен**.
Plesk только копирует файлы — `composer` и `migrate` запускаются через «Дополнительные действия развертывания».

## 1. Один раз: настройка в Plesk

**Git → Настройки репозитория:**
- URL: `https://github.com/baproger/qazaqtas.kz.git`, ветка `master`
- Режим развёртывания: **Автоматически**
- **Путь сервера — ВАЖНО:** для Laravel деплой должен идти в КОРЕНЬ домена (например `/erp.qazaqtas.kz`), а document root домена — в подпапку `/erp.qazaqtas.kz/public`.
  ⚠️ Если деплой настроен прямо в `.../public`, то `app/`, `.env`, `config/` окажутся в веб-доступе — это дыра (можно скачать `.env` с паролями БД). Проверить: document root сайта = `.../public`, а Git-деплой = на уровень выше.

**Веб-хук для авто-деплоя:** скопировать «URL-адрес для веб-хука» из Plesk →
GitHub → репозиторий → Settings → Webhooks → Add webhook → вставить URL,
Content type `application/json`, событие `Just the push event`. Без этого Plesk
не узнает о новом пуше сразу (будет тянуть только по расписанию/вручную).

## 2. Один раз: файл .env на сервере

`.env` НЕ хранится в git (секреты). Создать на сервере вручную (Plesk → Файлы,
в корне проекта), минимум:

```
APP_NAME="QAZAQ TAS ERP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.qazaqtas.kz
APP_KEY=                      # сгенерировать: php artisan key:generate
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=<имя_бд>
DB_USERNAME=<пользователь>
DB_PASSWORD=<пароль>
SESSION_SECURE_COOKIE=true
```

## PHP-версия

Зависимости закреплены под **PHP 8.3** (`config.platform.php` в composer.json),
поэтому системный `php` в Plesk-шелле (alt-php 8.3.30) работает — отдельный
путь к 8.4 больше НЕ нужен. Warning про `pdo_oci.so` — безвредный (битое
расширение системного PHP, к сайту не относится). Если когда-нибудь версия
сервера станет ниже 8.3 — обновить `platform.php` и пересобрать `composer.lock`.

## 3. Один раз: первичная инициализация (Plesk → PHP-консоль / SSH в корне проекта)

```bash
php composer.phar install --no-dev --optimize-autoloader
php artisan key:generate         # если APP_KEY пустой
php artisan migrate --force
php artisan db:seed --force       # роли + первый админ admin@qazaqtas.kz/password — СМЕНИТЬ пароль!
php artisan storage:link
php artisan optimize              # config+route+view cache
```

## 4. Автоматически при каждом пуше: Plesk «Дополнительные действия развертывания»

Включить галочку «Включить дополнительные действия развертывания» и вставить:

```bash
php composer.phar install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

⚠️ `composer.phar install` ОБЯЗАТЕЛЬНО в списке: `vendor/` не в git, git-pull
его не приносит — пересобирает только composer по `composer.lock`. Без этого
сервер останется на старом `vendor/` и упадёт на platform_check.

⚠️ **Composer и Node НЕ установлены в Plesk-шелле** (`command not found`).
Решение:
- **Composer** — сам `composer.phar` лежит в репозитории; вызываем через PHP:
  `php composer.phar install --no-dev` (PHP в шелле есть). Ставит `vendor/` на
  сервере под правильную версию PHP. Нужен исходящий интернет с сервера (обычно
  есть на Plesk).
- **Node/npm НЕ нужен** — собранный фронт `public/build/` уже в репозитории и
  приезжает с гитом.

## Права на папки (один раз, если 500-е ошибки)

```bash
chmod -R 775 storage bootstrap/cache
```

## Бэкапы

Фотографии товаров, категорий, объектов и 3D-модели лежат только в
`storage/app/public` — в git их нет. Без архива этой папки восстановить
их невозможно.

```bash
./scripts/backup.sh /var/backups/qazaqtas
```

Скрипт кладёт рядом дамп базы и `files_*.tar.gz` с загруженными файлами,
сам подчищает архивы старше 14 дней (`BACKUP_KEEP_DAYS` меняет срок).

По расписанию:

```
0 3 * * *  /var/www/qazaqtas/scripts/backup.sh /var/backups/qazaqtas
```

Раз в месяц проверять восстановление: развернуть дамп на копии и открыть
каталог — фотографии должны быть на месте.

## Сборка фронтенда

`public/build` в репозитории не хранится. Собранные файлы приезжают
артефактом из GitHub Actions (`.github/workflows/ci.yml`, артефакт `build`)
либо собираются на сервере:

```bash
npm ci && npm run build
```

## Кэш статики (nginx)

Собранные файлы и загруженные снимки не меняются под теми же именами —
Vite добавляет хэш в имя, MediaService генерирует случайное. Значит их
можно кэшировать навсегда.

```nginx
location /build/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}

location /storage/ {
    expires 30d;
    add_header Cache-Control "public";
    access_log off;
}
```

Рядом с каждым снимком лежит копия в WebP — он легче исходника примерно
вдвое и держит прозрачность. Витрина отдаёт его через `<picture>`,
исходный PNG или JPEG остаётся запасным для старых браузеров.

## Планировщик (обязательно)

Отложенные роботы этапов («через N часов»), напоминания о просрочках и
удержания долгов работают через планировщик Laravel. На сервере нужен cron:

```
* * * * * cd /path/to/qazaqtas.kz && php artisan schedule:run >> /dev/null 2>&1
```

Проверить: `php artisan schedule:list` — в списке должен быть `robots:run-due` (каждую минуту).

## Безопасность после деплоя (аудит 31.08.2026)

После обновления проверить три вещи:

1. **`storage/app/public/.htaccess` доехал на сервер.** Он запрещает
   исполнение PHP в каталоге загрузок и ставит nosniff — статику из
   `public/storage` Apache отдаёт мимо Laravel, и это единственный барьер.
   Файл в git; если Plesk не скопировал — положить руками.
2. **CSP теперь блокирующий** (был Report-Only). Открыть витрину и ERP,
   заглянуть в консоль браузера: нарушений `Content-Security-Policy` быть
   не должно. Инлайн-скрипт Ziggy подписывается nonce автоматически.
3. **Кука сессии.** `SESSION_SECURE_COOKIE=true` в `.env` уже требовался;
   теперь и без переменной в production действует `secure` по умолчанию.

Также: `DemoSeeder` в production не запускается (демо-пароль из репозитория),
вход отключённого сотрудника закрыт, у ключевых сотрудников — персональный
код входа (выдаёт администратор в карточке сотрудника, Сотрудники → профиль).

## Лимиты загрузки файлов (PHP)

Дефолтный `upload_max_filesize=2M` режет фото с телефона (4–8 МБ) ещё до
Laravel — загрузка «молча» не работает. Витрина и ERP жмут фото на
устройстве, но страховка на сервере обязательна. В Plesk → PHP-настройки:

```
upload_max_filesize = 10M
post_max_size = 32M
```

Локально: `php -d upload_max_filesize=10M -d post_max_size=32M artisan serve`.

## Скорость на проде (TTFB)

Смена языка и первый заход — полные загрузки, их скорость решает сервер:

1. **OPcache** в Plesk → PHP-настройки: `opcache.enable=1` (даёт кратный
   прирост TTFB — без него PHP перекомпилирует весь фреймворк на каждый запрос).
2. После деплоя: `php artisan optimize` (кеш конфигов и маршрутов).

SPA-переходы уже ускорены prefetch'ем: страницы подтягиваются при наведении
на ссылку и повторные переходы отвечают из кэша (Inertia v2, cache-for 1m).
