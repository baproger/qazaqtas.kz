<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Notifications\ServiceModerated;
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
        ]);
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
