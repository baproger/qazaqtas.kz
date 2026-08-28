<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Подсказка по БИН: справочник, а не часть сделки.
 *
 * Форма новой сделки дёргает его на лету, но к жизненному циклу сделки он
 * отношения не имеет — поэтому живёт отдельно.
 */
class BinLookupController extends Controller
{
    /**
     * Look up an existing company by БИН (deals first, then clients).
     * Used by the create form to offer copying company data.
     */
    public function binLookup(Request $request): JsonResponse
    {
        $this->authorize('create', Deal::class);
        $bin = trim($request->string('bin')->toString());
        if ($bin === '') {
            return response()->json(['match' => null, 'history' => []]);
        }

        // Изоляция фирм: подсказки по БИН — только по сделкам ТЕКУЩЕЙ компании,
        // иначе менеджер одной фирмы по БИН увидел бы бюджеты/сделки другой.
        // БИН заказчика живёт в `customer_bin`; в старых сделках он мог попасть
        // в `bin` — там, где сейчас «Номер договора». Ищем в обоих, иначе
        // подсказка молчала бы по всей истории до переезда полей.
        $byBin = fn ($q) => $q->where(fn ($w) => $w->where('customer_bin', $bin)->orWhere('bin', $bin));

        $client = Client::where('inn', $bin)->first();
        $deal = Deal::forCurrentCompany()->tap($byBin)->whereNotNull('company_name')->latest()->first();

        $match = null;
        if ($client) {
            $match = ['company_name' => $client->name, 'bin' => $client->inn, 'phone' => $client->phone, 'address' => $client->address];
        } elseif ($deal) {
            $match = ['company_name' => $deal->company_name, 'bin' => $deal->customer_bin ?: $deal->bin, 'phone' => $deal->contact_phone, 'address' => $deal->address];
        }

        // История по БИН — тоже только текущая компания.
        $history = Deal::forCurrentCompany()->tap($byBin)->with('stage:id,name,color')
            ->latest()->limit(30)
            ->get(['id', 'number', 'company_name', 'client_name', 'budget', 'deadline', 'deal_stage_id', 'created_at'])
            ->map(fn ($d) => [
                'id' => $d->id, 'number' => $d->number,
                'company' => $d->company_name, 'client' => $d->client_name,
                'budget' => (float) $d->budget, 'deadline' => optional($d->deadline)->toDateString(),
                'stage' => optional($d->stage)->name, 'color' => optional($d->stage)->color,
                'created' => optional($d->created_at)->toDateString(),
            ]);

        return response()->json(['match' => $match, 'history' => $history]);
    }
}
