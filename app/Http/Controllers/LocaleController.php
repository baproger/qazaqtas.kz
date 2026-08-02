<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', 'in:ru,kk']]);

        $request->session()->put('locale', $validated['locale']);

        if ($user = $request->user()) {
            $user->update(['language' => $validated['locale']]);
        }

        return back();
    }
}
