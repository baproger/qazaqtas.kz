<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * The single profile page. Role-aware: admins/directors can view & edit any
     * employee; everyone else sees only their own card. Own card also exposes
     * password change and account deletion.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->hasAnyRole(['admin', 'director']);

        return Inertia::render('Profile/Card', [
            'me' => $this->userData($user),
            'isAdmin' => $isAdmin,
            'employees' => $isAdmin
                ? \App\Models\User::where('is_active', true)->orderBy('name')->get()->map(fn ($u) => $this->userData($u))->values()
                : [],
            'departments' => \App\Models\Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // Profile data (incl. email) is editable by admins/directors only.
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    public function updateCard(Request $request, \App\Models\User $user): RedirectResponse
    {
        // Only admins/directors may edit profile data (name, email, phone, department) —
        // for anyone, including their own account. Regular users have a read-only profile.
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        // Changing the email invalidates its verification.
        if ($user->email !== $data['email']) {
            $data['email_verified_at'] = null;
        }

        // Role is intentionally NOT editable here.
        $user->update($data);

        return back()->with('success', 'Профиль сохранён.');
    }

    /** Any user may set their OWN avatar (the only self-editable profile field). */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);

        $user = $request->user();
        if ($old = $user->getRawOriginal('avatar')) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($old);
        }
        $user->update(['avatar' => $request->file('avatar')->store('avatars', 'local')]);

        return back()->with('success', 'Фото профиля обновлено.');
    }

    /** Serve a user's avatar image (auth-gated). */
    public function avatarShow(\App\Models\User $user)
    {
        $path = $user->getRawOriginal('avatar');
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($path);
    }

    private function userData(\App\Models\User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'avatar' => $u->avatar,
            'department_id' => $u->department_id,
            'role' => $u->getRoleNames()->first(),
        ];
    }
}
