<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Varsite\Platform\Http\Requests\LoginRequest;

/**
 * Uwierzytelnianie panelu (R2): logowanie tokenem Sanctum (Bearer).
 * Świadomy wybór dla shared-hosting-first i możliwej osobnej domeny API
 * (ADR-0005): brak zależności od sesji/cookies między originami.
 */
final class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var \Illuminate\Contracts\Auth\Authenticatable|null $user */
        $user = Auth::getProvider()->retrieveByCredentials(['email' => $request->string('email')->toString()]);

        // Stały koszt niezależnie od istnienia konta — bez wyroczni czasowej (user enumeration).
        $hash = $user?->getAuthPassword() ?? '$2y$12$C7ZDx1RDnryIzL8sm4uW7uh0kQ9eEqKQ0mYFI5S8u0dOKZKZTe3Vu';
        $valid = Hash::check($request->string('password')->toString(), $hash);

        if ($user === null || ! $valid) {
            throw ValidationException::withMessages([
                'email' => ['Nieprawidłowy e-mail lub hasło.'],
            ]);
        }

        $days = (int) config('platform.auth.token_lifetime_days', 30);
        $token = $user->createToken('panel', ['*'], $days > 0 ? now()->addDays($days) : null)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['data' => ['ok' => true]]);
    }
}
