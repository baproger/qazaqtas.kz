<?php

namespace App\Http\Controllers;

use App\Support\LiveStamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Живые» обновления без WebSocket-сервера и без нагрузки: браузер спрашивает
 * штамп (одно чтение из кеша, к БД не обращаемся), при совпадении ETag —
 * 304 с пустым телом. Данные догружаются только когда штамп сдвинулся.
 * Интервал задаёт браузер: 30 с, при тишине замедляется до 2 мин, в фоне — 5.
 */
class LiveController extends Controller
{
    public function version(Request $request): JsonResponse
    {
        $stamp = LiveStamp::get($request->user()->id);
        $etag = '"'.md5(json_encode($stamp)).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304);
        }

        return response()->json($stamp)->setEtag($etag)->header('Cache-Control', 'no-cache, private');
    }
}
