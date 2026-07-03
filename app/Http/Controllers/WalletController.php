<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * GET /api/wallets
     * List all wallets for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $wallets = $request->user()
            ->wallets()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'wallets' => $wallets,
        ]);
    }

    /**
     * POST /api/wallets
     * Create a new wallet.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        // Check if wallet with same name already exists
        $existing = $request->user()->wallets()->where('name', $validated['name'])->first();
        if ($existing) {
            return response()->json([
                'message' => 'Dompet dengan nama tersebut sudah ada',
                'wallet' => $existing,
            ], 409);
        }

        $wallet = $request->user()->wallets()->create([
            'name' => $validated['name'],
            'balance' => 0,
            'is_default' => false,
        ]);

        return response()->json([
            'message' => 'Dompet berhasil dibuat',
            'wallet' => $wallet,
        ], 201);
    }
}
