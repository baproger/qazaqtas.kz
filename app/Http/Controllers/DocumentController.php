<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    // Документ доступен только тому, кто видит родительскую сделку/проект
    // (менеджер — свои, цех — проекты цеха). Тот же ownership-контроль, что на финансах.
    private function assertEntityAccess(?Model $entity): void
    {
        abort_unless($entity && request()->user()->can('view', $entity), 403);
    }

    private function resolve(?string $type, ?int $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return $type === 'project' ? Project::find($id) : Deal::find($id);
    }

    public function store(DocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $file = $request->file('file');
        $name = $request->input('name') ?: $file->getClientOriginalName();
        $type = $request->input('documentable_type');
        $id = (int) $request->input('documentable_id');
        $this->assertEntityAccess($this->resolve($type, $id));

        // Store with a random name outside the public root (storage/app/private).
        $path = $file->store('documents', 'local');

        DB::transaction(function () use ($type, $id, $name, $path, $file) {
            // Versioning: deactivate previous versions of the same-named document.
            $prev = Document::where('documentable_type', $type)
                ->where('documentable_id', $id)
                ->where('name', $name)
                ->lockForUpdate();

            $version = (clone $prev)->max('version');
            $prev->update(['is_active' => false]);

            Document::create([
                'documentable_type' => $type,
                'documentable_id' => $id,
                'name' => $name,
                'file_path' => $path,
                'version' => ($version ?? 0) + 1,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'user_id' => request()->user()->id,
                'is_active' => true,
            ]);
        });

        return back()->with('success', 'Документ загружен.');
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        $this->assertEntityAccess($document->documentable);

        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->name);
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);
        $this->assertEntityAccess($document->documentable);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Документ удалён.');
    }
}
