<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /** Register a normal user and return a Sanctum API token. */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users'],
            'phone_number' => ['required', 'string', 'max:30', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $validated['role'] = UserRole::User;
        $user = User::create($validated);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken('flutter')->plainTextToken,
        ], 201);
    }

    /** Authenticate a user and create a new Sanctum API token. */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        return [
            'user' => new UserResource($user),
            'token' => $user->createToken('flutter')->plainTextToken,
        ];
    }

    /** Return the currently authenticated user. */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** Update the authenticated user's basic account details. */
    public function updateProfile(Request $request): UserResource
    {
        $user = $request->user();
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone_number' => [
                'required',
                'string',
                'max:30',
                Rule::unique('users', 'phone_number')->ignore($user->id),
            ],
        ]);

        $user->update($validated);

        return new UserResource($user->refresh());
    }

    /** Verify the authenticated user's password without exposing it to Flutter. */
    public function verifyPassword(Request $request)
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['password'], $request->user()->password)) {
            return response()->json([
                'message' => 'The password is incorrect.',
                'errors' => ['password' => ['The password is incorrect.']],
            ], 422);
        }

        return response()->json(['verified' => true]);
    }

    /** Change the authenticated user's regular account password. */
    public function changePassword(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $user->update(['password' => $validated['password']]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /** Replace an administrator-issued temporary team password. */
    public function changeInitialPassword(Request $request)
    {
        $user = $request->user();

        abort_unless(
            in_array($user->role, [UserRole::SosTeam, UserRole::DonationTeam], true),
            403,
            'Only team members can use the initial password change.'
        );
        abort_unless(
            $user->must_change_password,
            422,
            'The initial password has already been changed.'
        );

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The temporary password is incorrect.',
                'errors' => ['current_password' => ['The temporary password is incorrect.']],
            ], 422);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Choose a password different from the temporary password.',
                'errors' => ['password' => ['Choose a different password.']],
            ], 422);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        return response()->json([
            'message' => 'Password changed successfully.',
            'user' => new UserResource($user),
        ]);
    }

    /** Revoke the Sanctum token used for the current request. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
