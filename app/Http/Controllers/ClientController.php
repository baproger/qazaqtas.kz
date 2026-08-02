<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->with('responsible:id,name')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('inn', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%"))
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => $request->only('search', 'type'),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $request->user()->can('create', Client::class),
                'update' => $request->user()->can('update', Client::class),
                'delete' => $request->user()->can('delete', Client::class),
            ],
        ]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $this->authorize('create', Client::class);
        Client::create($request->validated());

        return back()->with('success', 'Контрагент создан.');
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);
        $client->update($request->validated());

        return back()->with('success', 'Контрагент обновлён.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);
        $client->delete();

        return back()->with('success', 'Контрагент удалён.');
    }
}
