<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->update(['read_at' => now()]);

        return back();
    }

    /**
     * `silent` — автоотметка при открытии колокольчика: сообщение об успехе
     * тут не нужно, человек ничего не нажимал осознанно.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $request->boolean('silent')
            ? back()
            : back()->with('success', 'Все уведомления прочитаны.');
    }
}
