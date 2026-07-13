<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\GeminiParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    private GeminiParserService $parser;

    // Valid categories as defined in PRD
    private const VALID_CATEGORIES = [
        'Makanan & Minuman',
        'Transport',
        'Tagihan',
        'Gaji',
        'Investasi',
        'Belanja Harian',
        'Pendapatan Usaha',
        'Lainnya',
    ];

    public function __construct(GeminiParserService $parser)
    {
        $this->parser = $parser;
    }

    /**
     * POST /api/transactions/parse
     * Parse text input via Gemini AI and save transaction.
     */
    public function parse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        $textLower = strtolower($validated['text']);
        $recapKeywords = ['rekap', 'total pengeluaran', 'total pemasukan', 'total belanja', 'pengeluaran saya', 'pemasukan saya', 'berapa pengeluaran', 'berapa pemasukan', 'uang keluar', 'uang masuk'];
        $isRecap = false;
        foreach ($recapKeywords as $kw) {
            if (str_contains($textLower, $kw)) {
                $isRecap = true;
                break;
            }
        }

        if ($isRecap) {
            $parsed = ['intent' => 'recap'];
        } else if (preg_match('/^(hallo|halo|hai|hi|hei|helo)(.*)?$/i', trim($validated['text']))) {
            return response()->json([
                'message' => 'Hallo saya Asep AI yang dibuat oleh Adip Setiaputra yang ganteng kata mamanya. Saya di sini disuruh mencatat pendapatan dan pengeluaran harian Anda, seperti itu ',
                'is_greeting' => true,
                'transactions' => []
            ], 200);
        } else {
            try {
                $parsed = $this->parser->parseText($validated['text']);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        if (isset($parsed['intent']) && $parsed['intent'] === 'recap') {
            $user = $request->user();
            $todayTransactions = $user->transactions()
                ->with(['wallet', 'receiptItems'])
                ->whereDate('created_at', today())
                ->get();
            
            // Generate summary via Gemini
            $summary = $this->parser->generateRecapSummary($todayTransactions->toArray(), $user->name);

            return response()->json([
                'message' => $summary,
                'is_recap' => true,
                'transactions' => $todayTransactions,
                'recap_urls' => [
                    'pdf' => url('/api/export/pdf'),
                    'excel' => url('/api/export/excel'),
                    'word' => url('/api/export/word'),
                ]
            ], 200);
        }

        // Ensure $parsed is an array of transactions for unified processing
        $transactionsData = [];
        $isMulti = false;
        
        if (isset($parsed[0]) && is_array($parsed[0])) {
            $transactionsData = $parsed;
            $isMulti = true;
        } else {
            // Validate required fields for single transaction
            if (empty($parsed['jenis']) || !in_array($parsed['jenis'], ['masuk', 'keluar'])) {
                return response()->json([
                    'message' => 'Asep AI tidak bisa menentukan jenis transaksi (masuk/keluar). Coba tulis lebih jelas.',
                ], 422);
            }

            if (empty($parsed['nominal']) || !is_numeric($parsed['nominal']) || $parsed['nominal'] <= 0) {
                return response()->json([
                    'message' => 'Asep AI tidak bisa menentukan nominal transaksi. Coba sertakan angka yang jelas.',
                ], 422);
            }
            $transactionsData = [$parsed];
        }

        $user = $request->user();
        $transactions = [];

        DB::transaction(function () use ($transactionsData, $user, $validated, &$transactions) {
            foreach ($transactionsData as $item) {
                if (empty($item['jenis']) || !in_array($item['jenis'], ['masuk', 'keluar'])) continue;
                if (empty($item['nominal']) || !is_numeric($item['nominal']) || $item['nominal'] <= 0) continue;

                $category = $item['kategori'] ?? 'Lainnya';
                if (!in_array($category, self::VALID_CATEGORIES)) {
                    $category = 'Lainnya';
                }

                $walletName = !empty(trim($item['dompet'] ?? '')) ? trim($item['dompet']) : 'Cash';
                $wallet = $this->findOrCreateWallet($user, $walletName);

                $transactionsToCreate = [];
                
                // Logic to split ShopeePay Topup into Debt Repayment vs Actual Income
                if ($item['jenis'] === 'masuk' && ($item['sumber'] ?? '') === 'SHOPEE_TOPUP' && $wallet->balance < 0) {
                    $debt = abs($wallet->balance);
                    $nominal = (int) $item['nominal'];
                    
                    $repayment = min($nominal, $debt);
                    $income = $nominal - $repayment;
                    
                    // 1. Debt repayment (won't count as revenue)
                    $tx1 = $item;
                    $tx1['nominal'] = $repayment;
                    $tx1['sumber'] = 'SISTEM_TRANSFER'; 
                    $tx1['catatan'] = ($item['catatan'] ?? '') . ' (Bayar Hutang)';
                    $transactionsToCreate[] = $tx1;
                    
                    // 2. Remaining income (counts as revenue)
                    if ($income > 0) {
                        $tx2 = $item;
                        $tx2['nominal'] = $income;
                        $tx2['sumber'] = 'Pendapatan'; 
                        $tx2['catatan'] = ($item['catatan'] ?? '') . ' (Sisa Pendapatan)';
                        $transactionsToCreate[] = $tx2;
                    }
                } else {
                    // If no debt, ensure it counts as income
                    if ($item['jenis'] === 'masuk' && ($item['sumber'] ?? '') === 'SHOPEE_TOPUP') {
                        $item['sumber'] = 'Pendapatan';
                    }
                    $transactionsToCreate[] = $item;
                }

                foreach ($transactionsToCreate as $txData) {
                    $tx = Transaction::create([
                        'user_id' => $user->id,
                        'wallet_id' => $wallet->id,
                        'type' => $txData['jenis'],
                        'amount' => (int) $txData['nominal'],
                        'category' => $category,
                        'item' => $this->nullIfEmpty($txData['item'] ?? ''),
                        'platform' => $this->nullIfEmpty($txData['platform'] ?? ''),
                        'source' => $this->nullIfEmpty($txData['sumber'] ?? ''),
                        'note' => $txData['catatan'] ?? null,
                        'raw_input' => $validated['text'],
                        'source_type' => 'chat',
                    ]);

                    // Update wallet balance
                    if ($txData['jenis'] === 'masuk') {
                        $wallet->increment('balance', (int) $txData['nominal']);
                    } else {
                        $wallet->decrement('balance', (int) $txData['nominal']);
                    }

                    $tx->load('wallet');
                    $transactions[] = $tx;
                }
            }
        });

        if ($isMulti) {
            return response()->json([
                'message' => count($transactions) . ' transaksi berhasil dicatat',
                'transactions' => $transactions,
                'parsed' => $parsed,
                'is_multi' => true,
            ], 201);
        }

        return response()->json([
            'message' => 'Transaksi berhasil dicatat',
            'transaction' => $transactions[0] ?? null,
            'transactions' => $transactions,
            'parsed' => $parsed,
        ], 201);
    }

    /**
     * POST /api/transactions/parse-receipt
     * Parse receipt image via Gemini Vision AI and save transaction + receipt items.
     */
    public function parseReceipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt' => ['required', 'image', 'max:10240'], // Max 10MB
        ]);

        $file = $request->file('receipt');
        $base64 = base64_encode(file_get_contents($file->getPathname()));
        $mimeType = $file->getMimeType();

        // Store the image locally
        $path = $file->store('receipts', 'public');
        $imageUrl = asset('storage/' . $path);

        try {
            $parsed = $this->parser->parseReceipt($base64, $mimeType);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        // Validate required fields
        if (empty($parsed['nominal']) || !is_numeric($parsed['nominal']) || $parsed['nominal'] <= 0) {
            return response()->json([
                'message' => 'Asep AI tidak bisa membaca total belanja dari struk. Coba foto ulang dengan lebih jelas.',
            ], 422);
        }

        // Normalize category
        $category = $parsed['kategori'] ?? 'Belanja Harian';
        if (!in_array($category, self::VALID_CATEGORIES)) {
            $category = 'Belanja Harian';
        }

        $user = $request->user();
        $wallet = $this->findOrCreateWallet($user, 'Cash');

        // Save transaction + receipt items
        $transaction = DB::transaction(function () use ($user, $wallet, $parsed, $category, $imageUrl) {
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => 'keluar',
                'amount' => (int) $parsed['nominal'],
                'category' => $category,
                'store' => $this->nullIfEmpty($parsed['toko'] ?? ''),
                'note' => $parsed['catatan'] ?? null,
                'raw_input' => $imageUrl,
                'source_type' => 'receipt',
            ]);

            // Save receipt items
            if (!empty($parsed['items']) && is_array($parsed['items'])) {
                foreach ($parsed['items'] as $item) {
                    $transaction->receiptItems()->create([
                        'item_name' => $item['nama'] ?? 'Unknown',
                        'price' => (int) ($item['harga'] ?? 0),
                        'qty' => (int) ($item['qty'] ?? 1),
                    ]);
                }
            }

            // Update wallet balance (receipt is always keluar)
            $wallet->decrement('balance', (int) $parsed['nominal']);

            return $transaction;
        });

        $transaction->load(['wallet', 'receiptItems']);

        return response()->json([
            'message' => 'Struk berhasil dibaca dan dicatat',
            'transaction' => $transaction,
            'parsed' => $parsed,
        ], 201);
    }

    /**
     * GET /api/transactions/today
     * Get today's transactions for the authenticated user.
     */
    public function today(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->transactions()
            ->with(['wallet', 'receiptItems'])
            ->whereDate('created_at', today())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'transactions' => $transactions,
        ]);
    }

    /**
     * GET /api/transactions
     * Get transactions with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->transactions()->with(['wallet', 'receiptItems']);

        // Filter by date
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        // Filter by date range
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereDate('created_at', '>=', $request->start_date)
                  ->whereDate('created_at', '<=', $request->end_date);
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by wallet
        if ($request->filled('wallet_id')) {
            $query->where('wallet_id', $request->wallet_id);
        }

        // Filter by type (masuk/keluar)
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(30);

        return response()->json($transactions);
    }

    /**
     * PUT /api/transactions/{id}
     * Edit a transaction manually.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($id);

        $validated = $request->validate([
            'type' => ['sometimes', 'in:masuk,keluar'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'category' => ['sometimes', 'in:' . implode(',', self::VALID_CATEGORIES)],
            'item' => ['sometimes', 'nullable', 'string', 'max:255'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'store' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'wallet_id' => ['sometimes', 'exists:wallets,id'],
        ]);

        DB::transaction(function () use ($transaction, $validated) {
            $oldType = $transaction->type;
            $oldAmount = $transaction->amount;
            $oldWalletId = $transaction->wallet_id;

            // Revert old wallet balance
            $oldWallet = Wallet::find($oldWalletId);
            if ($oldType === 'masuk') {
                $oldWallet->decrement('balance', $oldAmount);
            } else {
                $oldWallet->increment('balance', $oldAmount);
            }

            // Update transaction
            $transaction->update($validated);

            // Apply new wallet balance
            $newWallet = Wallet::find($transaction->wallet_id);
            if ($transaction->type === 'masuk') {
                $newWallet->increment('balance', $transaction->amount);
            } else {
                $newWallet->decrement('balance', $transaction->amount);
            }
        });

        $transaction->load(['wallet', 'receiptItems']);

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui',
            'transaction' => $transaction,
        ]);
    }

    /**
     * DELETE /api/transactions/{id}
     * Delete a transaction and revert wallet balance.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($id);

        DB::transaction(function () use ($transaction) {
            // Revert wallet balance
            $wallet = $transaction->wallet;
            if ($transaction->type === 'masuk') {
                $wallet->decrement('balance', $transaction->amount);
            } else {
                $wallet->increment('balance', $transaction->amount);
            }

            $transaction->delete();
        });

        return response()->json([
            'message' => 'Transaksi berhasil dihapus',
        ]);
    }

    /**
     * Find or create a wallet by name for a user.
     */
    private function findOrCreateWallet($user, string $name): Wallet
    {
        return $user->wallets()->firstOrCreate(
            ['name' => $name],
            ['balance' => 0, 'is_default' => false]
        );
    }

    /**
     * Return null if string is empty, otherwise return the trimmed string.
     */
    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        return trim($value);
    }
}
