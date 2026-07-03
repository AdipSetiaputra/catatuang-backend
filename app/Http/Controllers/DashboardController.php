<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/summary
     * Daily summary: total masuk, total keluar, saldo per wallet, top categories.
     * Only counts transactions with created_at = today (resets daily per PRD).
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = today();

        // Today's transactions
        $todayTransactions = $user->transactions()
            ->whereDate('created_at', $today)
            ->get();

        $totalMasuk = $todayTransactions->where('type', 'masuk')->sum('amount');
        $totalKeluar = $todayTransactions->where('type', 'keluar')->sum('amount');

        // Top categories today (top 4)
        $topCategories = $todayTransactions
            ->groupBy('category')
            ->map(function ($group, $category) {
                return [
                    'category' => $category,
                    'total' => $group->sum('amount'),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('total')
            ->take(4)
            ->values();

        // All wallets with current balance
        $wallets = $user->wallets()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'balance', 'is_default']);

        $totalBalance = $wallets->sum('balance');

        // Monthly summary (current month)
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $monthlyTransactions = $user->transactions()
            ->whereDate('created_at', '>=', $monthStart)
            ->whereDate('created_at', '<=', $monthEnd)
            ->get();

        $monthlyMasuk = $monthlyTransactions->where('type', 'masuk')->sum('amount');
        $monthlyKeluar = $monthlyTransactions->where('type', 'keluar')->sum('amount');

        // Weekly summary (current week)
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $weeklyTransactions = $user->transactions()
            ->whereDate('created_at', '>=', $weekStart)
            ->whereDate('created_at', '<=', $weekEnd)
            ->get();

        $weeklyMasuk = $weeklyTransactions->where('type', 'masuk')->sum('amount');
        $weeklyKeluar = $weeklyTransactions->where('type', 'keluar')->sum('amount');

        // 7-day chart data
        $chartStart = now()->subDays(6)->startOfDay();
        $chartEnd = now()->endOfDay();
        $chartTransactions = $user->transactions()
            ->whereDate('created_at', '>=', $chartStart)
            ->whereDate('created_at', '<=', $chartEnd)
            ->get();

        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dateString = $date->format('Y-m-d');
            $displayDate = $date->translatedFormat('d M'); // e.g., "25 Jun"

            $dayTxs = $chartTransactions->filter(function($tx) use ($dateString) {
                return $tx->created_at->format('Y-m-d') === $dateString;
            });

            $chartData[] = [
                'date' => $displayDate,
                'masuk' => $dayTxs->where('type', 'masuk')->sum('amount'),
                'keluar' => $dayTxs->where('type', 'keluar')->sum('amount'),
            ];
        }

        return response()->json([
            'today' => [
                'total_masuk' => $totalMasuk,
                'total_keluar' => $totalKeluar,
                'net' => $totalMasuk - $totalKeluar,
                'transaction_count' => $todayTransactions->count(),
                'top_categories' => $topCategories,
            ],
            'monthly' => [
                'total_masuk' => $monthlyMasuk,
                'total_keluar' => $monthlyKeluar,
                'net' => $monthlyMasuk - $monthlyKeluar,
            ],
            'weekly' => [
                'total_masuk' => $weeklyMasuk,
                'total_keluar' => $weeklyKeluar,
                'net' => $weeklyMasuk - $weeklyKeluar,
            ],
            'chart_7_days' => $chartData,
            'wallets' => $wallets,
            'total_balance' => $totalBalance,
        ]);
    }
}
