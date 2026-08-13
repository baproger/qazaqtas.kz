<?php

namespace App\Http\Controllers;

use App\Models\SiteProject;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ERP → Объекты сайта: реализованные проекты с фотографиями. Их показывает
 * главная (скролл-история после 3D) и страница «Проекты».
 */
class SiteProjectController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function index(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('SiteProjects/Index', [
            'locales' => \App\Support\Locales::forForm(),
            // Форма правит базовые значения; переводы едут отдельным полем.
            'projects' => SiteProject::with('translations')
                ->orderBy('order')->orderByDesc('id')->get()
                ->map(fn (SiteProject $p) => $p->toArray() + [
                    'translations_map' => $p->translationsPayload(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        SiteProject::create($this->validated($request))
            ->saveTranslations($request->input('translations'));

        return back()->with('success', 'Объект добавлен.');
    }

    public function update(Request $request, SiteProject $project): RedirectResponse
    {
        $this->guard($request);
        $project->update($this->validated($request));
        $project->saveTranslations($request->input('translations'));

        return back()->with('success', 'Объект обновлён.');
    }

    public function destroy(Request $request, SiteProject $project): RedirectResponse
    {
        $this->guard($request);
        $this->media->delete($project->image, $project->thumb);
        $project->delete();

        return back()->with('success', 'Объект удалён.');
    }

    /** Фото объекта: ужимается так же, как фото товара. */
    public function uploadImage(Request $request, SiteProject $project): RedirectResponse
    {
        $this->guard($request);
        $request->validate(['image' => MediaService::IMAGE_RULES]);

        $this->media->delete($project->image, $project->thumb);
        $stored = $this->media->storeImage($request->file('image'), 'projects', $project->title);
        $project->update(['image' => $stored['path'], 'thumb' => $stored['thumb']]);

        return back()->with('success', 'Фотография объекта загружена.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $rules = ['translations' => ['nullable', 'array']];

        // Ни одно поле перевода не обязательно: пустое откатывается
        // к базовому значению объекта.
        foreach (\App\Support\Locales::ALL as $locale) {
            $rules["translations.$locale.title"] = ['nullable', 'string', 'max:180'];
            $rules["translations.$locale.city"] = ['nullable', 'string', 'max:80'];
            $rules["translations.$locale.products"] = ['nullable', 'string', 'max:255'];
            $rules["translations.$locale.description"] = ['nullable', 'string', 'max:2000'];
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:80'],
            'year' => ['nullable', 'string', 'max:10'],
            'area' => ['nullable', 'string', 'max:40'],
            'products' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            ...$rules,
        ]);

        unset($data['translations']);

        return $data;
    }

    private function guard(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(['admin', 'director', 'financist']),
            403,
            'Объекты сайта ведут админ, директор или финансист.'
        );
    }
}
