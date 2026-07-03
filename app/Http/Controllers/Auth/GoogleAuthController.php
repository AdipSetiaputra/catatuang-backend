<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect user to Google consent screen.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * Handle callback from Google.
     * - Check by google_id first
     * - If not found, check by email (prevent duplicate accounts)
     * - If brand new user, create with google_id and NULL password
     * - Generate Sanctum token and redirect to frontend with token
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            return redirect($frontendUrl . '/login?error=google_auth_failed');
        }

        // 1. Check if user exists by google_id
        $user = User::where('google_id', $googleUser->getId())->first();

        // 2. If not found, check by email (user may have registered manually before)
        if (!$user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                // Link existing account with Google
                $user->update(['google_id' => $googleUser->getId()]);
            }
        }

        // 3. If still no user, create new one
        if (!$user) {
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'password' => null,
            ]);
        }

        // Generate Sanctum token
        $token = $user->createToken('google-auth-token')->plainTextToken;

        // Redirect to frontend with token
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        return redirect($frontendUrl . '/auth/callback?token=' . $token);
    }
}
