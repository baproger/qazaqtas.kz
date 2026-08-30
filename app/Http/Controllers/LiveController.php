<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Живые» обновления без WebSocket-сервера: браузер раз в 10 с спрашивает
 * короткий штамп «что изменилось у меня», и только при изменении догружает
 * данные страницы. Один запрос — одна строка JSON, три индексных запроса к
 * БД; кеш браузера через ETag: без изменений ответ 304 и пустое тело.
 */
class LiveController extends Controller
{
    public function version(Request $request): JsonResponse
    {
        $user = $request->user();

        $tasks = Task::where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere('creator_id', $user->id))
            ->max('updated_at');
        $notifications = $user->notifications()->latest()->value('id');
        $unread = $user->unreadNotifications()->count();

        $stamp = [
            'tasks' => $tasks ? strtotime($tasks) : 0,
            'notifications' => (string) $notifications.':'.$unread,
        ];
        $etag = '"'.md5(json_encode($stamp)).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304);
        }

        return response()->json($stamp)->setEtag($etag)->header('Cache-Control', 'no-cache, private');
    }
}
