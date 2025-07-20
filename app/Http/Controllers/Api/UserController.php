<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $user = [
            'id' => uniqid(),
            'name' => $request->name,
            'email' => $request->email,
            'created_at' => now()->toDateTimeString(),
        ];

        Log::info('Creating user:', [
            'user' => $user,
            'idempotency_key' => $request->header('X-IDEMPOTENCY'),
        ]);

        sleep(2);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }
}
