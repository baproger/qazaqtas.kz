<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Notifications\ServiceModerated;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Модерация услуг — ассистент (и админ через Gate::before). */
class ServiceModerationController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['assistant', 'admin']), 403, 'Модерация услуг — для ассистента.');
    }

    public function index(Request $request): Response
    {
        $this->guard($request);
        $status = $request->string('status')->toString() ?: 'pending';

        return Inertia::render('Moderation/Services', [
            'services' => Service::with(['partner:id,name,phone', 'category:id,name'])
                ->when(in_array($status, array_keys(Service::STATUSES), true), fn ($q) => $q->where('status', $status))
                ->latest('updated_at')->paginate(30)->withQueryString()
                ->through(fn (Service $s) => $s->toCard() + [
                    'status' => $s->status, 'rejection_reason' => $s->rejection_reason,
                    'partner' => $s->partner?->only(['id', 'name', 'phone']),
                    'contact_name' => $s->contact_name, 'contact_phone' => $s->contact_phone,
                    'description_full' => $s->description, 'created_at' => $s->created_at?->toIso8601String(),
                ]),
            'counts' => Service::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'filters' => ['status' => $status],
            'statuses' => Service::STATUSES,
            // Категории — управляются здесь же: добавить, переименовать, скрыть.
            'categories' => ServiceCategory::with('translations')->withCount('services')->orderBy('sort')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'is_active' => $c->is_active, 'services_count' => $c->services_count,
                    'name_kk' => $c->translationsPayload()['kk']['name'] ?? null]),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100'], 'name_kk' => ['nullable', 'string', 'max:100']]);
        $category = ServiceCategory::create([
            'name' => strip_tags($data['name']),
            'slug' => Seo::slug($data['name'], ServiceCategory::class),
            'sort' => (int) ServiceCategory::max('sort') + 1,
            'is_active' => true,
        ]);
        $category->saveTranslations(['kk' => ['name' => strip_tags((string) ($data['name_kk'] ?? ''))]]);

        return back()->with('success', 'Категория добавлена.');
    }

    public function updateCategory(Request $request, ServiceCategory $category): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'], 'name_kk' => ['sometimes', 'nullable', 'string', 'max:100'], 'is_active' => ['sometimes', 'boolean']]);
        if (isset($data['name'])) {
            $data['name'] = strip_tags($data['name']);
        }
        if (array_key_exists('name_kk', $data)) {
            $category->saveTranslations(['kk' => ['name' => strip_tags((string) $data['name_kk'])]]);
            unset($data['name_kk']);
        }
        $category->update($data);

        return back()->with('success', 'Категория обновлена.');
    }

    public function destroyCategory(Request $request, ServiceCategory $category): RedirectResponse
    {
        $this->guard($request);
        // Услуги не удаляются: остаются «без категории» (FK nullOnDelete).
        $n = $category->services()->count();
        $category->delete();

        return back()->with('success', 'Категория удалена.'.($n ? " Услуг без категории: {$n}." : ''));
    }

    public function approve(Request $request, Service $service): RedirectResponse
    {
        $this->guard($request);
        $service->update(['status' => 'approved', 'rejection_reason' => null, 'moderated_at' => now(), 'moderated_by' => $request->user()->id]);
        $service->partner?->notify(new ServiceModerated($service));

        return back()->with('success', 'Услуга «'.$service->title.'» опубликована.');
    }

    public function reject(Request $request, Service $service): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $service->update(['status' => 'rejected', 'rejection_reason' => strip_tags($data['reason']), 'moderated_at' => now(), 'moderated_by' => $request->user()->id]);
        $service->partner?->notify(new ServiceModerated($service));

        return back()->with('success', 'Услуга отклонена, партнёр получил причину.');
    }
}
