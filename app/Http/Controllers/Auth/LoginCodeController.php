<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Второй шаг входа для ключевых сотрудников — персональный код доступа.
 *
 * Код выдаёт администратор в карточке сотрудника; в базе лежит только хэш.
 * К этому шагу пароль уже проверен (login.pending в сессии), но сессия всё
 * ещё гостевая: авторизация случается только после верного кода. Пять
 * неверных кодов подряд — минутная блокировка по сотруднику и IP.
 */
class LoginCodeController extends Controller
{
    /** Заявка на второй шаг живёт пять минут — дальше сначала пароль. */
    private const PENDING_TTL = 300;

    public function create(Request $request): \Inertia\Response|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/LoginCode');
    }

    public function store(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'digits:6']]);

        $key = 'login-code:'.$pending['id'].'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'code' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        $user = User::find($pending['id']);
        if (! $user || ! $user->is_active || ! $user->access_code || ! Hash::check($request->string('code'), $user->access_code)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['code' => 'Неверный код.']);
        }

        RateLimiter::clear($key);
        $request->session()->forget('login.pending');

        Auth::login($user, (bool) ($pending['remember'] ?? false));
        // Новая сессия после повышения прав — против фиксации сессии.
        $request->session()->regenerate();

        $companyId = (int) ($pending['company_id'] ?? 0);
        if ($companyId && $user->companies()->where('companies.id', $companyId)->exists()) {
            CurrentCompany::set($companyId);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** «Вернуться ко входу»: бросить второй шаг и начать с пароля. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('login.pending');

        return redirect()->route('login');
    }

    /** @return array{id: int, remember: bool, company_id: int, at: int}|null */
    private function pending(Request $request): ?array
    {
        $p = $request->session()->get('login.pending');
        if (! is_array($p) || ! isset($p['id'], $p['at']) || now()->getTimestamp() - (int) $p['at'] > self::PENDING_TTL) {
            $request->session()->forget('login.pending');

            return null;
        }

        return $p;
    }
}
