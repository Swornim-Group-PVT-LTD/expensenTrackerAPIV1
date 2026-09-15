<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    // Register new user
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name'                 => 'required|string|max:255',
                'middle_name'                => 'nullable|string|max:255',
                'last_name'                  => 'required|string|max:255',
                'email'                      => 'required|email|unique:users,email',
                'phonenumber'                => 'required|string|max:20',
                'password'                   => 'required|string|min:6',
                'country'                    => 'required|string|max:255',
                'address'                    => 'required|string|max:255',

                // Extra fields
                'user_type'                  => 'nullable|string|max:255',
                'logo_uploads'               => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'representative_full_name'   => 'nullable|string|max:255',
            ], [
                'first_name.required'               => 'First name is required.',
                'last_name.required'                => 'Last name is required.',
                'email.required'                    => 'Email is required.',
                'email.email'                       => 'Email format is invalid.',
                'email.unique'                      => 'This email is already registered.',
                'phonenumber.required'              => 'Phone number is required.',
                'password.required'                => 'Password is required.',
                'password.min'                     => 'Password must be at least 6 characters long.',
                'country.required'                 => 'Country is required.',
                'address.required'                 => 'Address is required.',
                'logo_uploads.image'               => 'Logo must be an image.',
                'logo_uploads.mimes'               => 'Logo must be a JPEG, PNG, JPG, GIF, or WEBP image.',
                'logo_uploads.max'                 => 'Logo size must not exceed 2MB.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Customer ID
        |--------------------------------------------------------------------------
        */

        $latestCustomer = User::orderByDesc('id')->first();

        $newNumericId = $latestCustomer
            ? (int) str_replace('CUST', '', $latestCustomer->customerid) + 1
            : 100000;

        $newCustomerId = 'CUST' . $newNumericId;

        /*
        |--------------------------------------------------------------------------
        | Logo Upload
        |--------------------------------------------------------------------------
        | Logo will be stored in:
        | public/logo_uploads/
        |--------------------------------------------------------------------------
        */

        $logoPath = null;

        if ($request->hasFile('logo_uploads')) {

            $logo = $request->file('logo_uploads');

            // Generate unique filename
            $filename = $newCustomerId . '_' . time() . '.' . $logo->getClientOriginalExtension();

            // Move logo to public/logo_uploads
            $logo->move(public_path('logo_uploads'), $filename);

            // Save relative path in database
            $logoPath = 'logo_uploads/' . $filename;
        }

        /*
        |--------------------------------------------------------------------------
        | Create User
        |--------------------------------------------------------------------------
        */

        $user = User::create([
            'customerid'                => $newCustomerId,
            'first_name'                => $validated['first_name'],
            'middle_name'               => $validated['middle_name'] ?? null,
            'last_name'                 => $validated['last_name'],
            'email'                     => $validated['email'],
            'phonenumber'               => $validated['phonenumber'],
            'password'                  => Hash::make($validated['password']),
            'country'                   => $validated['country'],
            'address'                   => $validated['address'],

            // Extra fields
            'user_type'                 => $validated['user_type'] ?? null,
            'logo_uploads'              => $logoPath,
            'representative_full_name'  => $validated['representative_full_name'] ?? null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Create Authentication Token
        |--------------------------------------------------------------------------
        */

        $token = $user->createToken('auth_token')->plainTextToken;

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' => 'User registered successfully',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 201);
    }
    // Login user
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password',
            ], 401); // Unauthorized
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    // Logout user
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Logged out successfully'
            ], 200);
        }

            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
    }

}
