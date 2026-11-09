<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login', [
            'showOwnerSetup' => ! User::query()->exists(),
        ]);
    }

    public function setup(): View
    {
        abort_if(User::query()->exists(), 404);

        return view('auth.setup');
    }

    public function storeInitialOwner(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        try {
            $owner = DB::transaction(function () use ($validated): User {
                abort_if(User::query()->exists(), 404);

                $owner = new User([
                    'name' => $validated['name'],
                    'email' => strtolower(trim($validated['email'])),
                    'role' => 'owner',
                    'password' => $validated['password'],
                ]);
                $owner->is_initial_owner = true;
                $owner->save();

                return $owner;
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Initial setup is already complete. Sign in with your account.']);
        }

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()->route('pos.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return back()
                ->withErrors(['email' => 'The email or password is incorrect.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('pos.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
