<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Notifications\ServiceSubmitted;
use App\Services\MediaService;
use App\Support\RoleTraits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Кабинет партнёра: свои услуги и заявки на публикацию.
 * Чужие услуги недоступны и по прямому id (ServicePolicy).
 */
class PartnerServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Service::class);
        abort_unless($request->user()->hasRole('partner') || $request->user()->hasRole('admin'), 403);

        return Inertia::render('Partner/Services', [
            'services' => Service::with('category:id,name')
                ->where('partner_id', $request->user()->id)
                ->latest()->get()
                ->map(fn (Service $s) => $s->toCard() + [
                    'status' => $s->status, 'statusLabel' => Service::STATUSES[$s->status],
                    'rejection_reason' => $s->rejection_reason, 'price_raw' => $s->price,
                    'category_id' => $s->category_id, 'contact_name' => $s->contact_name,
                    'contact_phone' => $s->contact_phone, 'description_raw' => $s->description,
                    'public_url' => $s->status === 'approved' ? route('site.service', $s->slug) : null,
                ]),
            'categories' => ServiceCategory::where('is_active', true)->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function store(ServiceRequest $request, MediaService $media): RedirectResponse
    {
        $this->authorize('create', Service::class);

        $service = new Service($this->clean($request->validated()));
        $service->partner_id = $request->user()->id;
        $service->status = 'pending';
        $this->attachPhoto($service, $request, $media);
        $service->save();

        $this->notifyModerators($service);

        return back()->with('success', 'Услуга отправлена на проверку — ответим в течение 24 часов.');
    }

    public function update(ServiceRequest $request, Service $service, MediaService $media): RedirectResponse
    {
        $this->authorize('update', $service);

        $service->fill($this->clean($request->validated()));
        $this->attachPhoto($service, $request, $media);
        // Любая правка возвращает услугу на модерацию: опубликованное нельзя
        // подменить содержимым, которого ассистент не видел.
        $service->status = 'pending';
        $service->rejection_reason = null;
        $service->save();

        $this->notifyModerators($service);

        return back()->with('success', 'Изменения отправлены на проверку — ответим в течение 24 часов.');
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);
        $service->delete();

        return back()->with('success', 'Услуга удалена.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function clean(array $data): array
    {
        // Описание — простой текст: HTML/скрипты вычищаются целиком.
        $data['description'] = trim(strip_tags((string) $data['description']));
        $data['title'] = trim(strip_tags((string) $data['title']));
        unset($data['photo']);

        return $data;
    }

    private function attachPhoto(Service $service, ServiceRequest $request, MediaService $media): void
    {
        if (! $request->hasFile('photo')) {
            return;
        }
        // Содержимое, а не имя: PHP-скрипт с расширением .jpg проходит
        // мимо MIME-guesser'а, но не мимо getimagesize — он читает байты.
        $info = @getimagesize($request->file('photo')->getRealPath());
        if (! in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([
                'photo' => 'Файл не является изображением JPG/PNG/WebP.',
            ]);
        }
        // Одно фото: сжатие до 1600px, превью, WebP-версии; имя случайное,
        // папка public/services — исполняемых файлов туда не попасть
        // (MIME проверен по содержимому, GD пересобирает картинку).
        $stored = $media->storeImage($request->file('photo'), 'services', $service->title);
        $service->photo = $stored['path'];
        $service->photo_thumb = $stored['webp_thumb'] ?? $stored['thumb'];
        $service->photo_webp = $stored['webp'] ?? null;
    }

    private function notifyModerators(Service $service): void
    {
        RoleTraits::users(['assistant', 'admin'])->where('is_active', true)->get()
            ->each->notify(new ServiceSubmitted($service));
    }
}
