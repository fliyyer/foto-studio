<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', 'max:50'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'] ?? 'customer',
        ]);

        $plainTextToken = Str::random(60);
        $user->api_token = hash('sha256', $plainTextToken);
        $user->save();

        return response()->json([
            'message' => 'Register success',
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'user' => $user,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Email or password is invalid',
            ], 401);
        }

        $plainTextToken = Str::random(60);
        $user->api_token = hash('sha256', $plainTextToken);
        $user->save();

        return response()->json([
            'message' => 'Login success',
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'user' => $user,
        ]);
    }
}
