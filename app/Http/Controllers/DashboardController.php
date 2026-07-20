<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

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
        
        $dateParam = $request->query('date');
        $targetDate = $dateParam ? Carbon::parse($dateParam)->startOfDay() : today();
        $targetDateEnd = $targetDate->copy()->endOfDay();

        // Target date transactions
        $todayTransactions = $user->transactions()
            ->whereDate('created_at', $targetDate)
            ->get();

        $totalMasuk = $todayTransactions->where('type', 'masuk')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount');
        $totalKeluar = $todayTransactions->where('type', 'keluar')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount');

        // Top categories target date (top 4)
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

        $totalBalance = 0;
        foreach ($wallets as $wallet) {
            $nameLower = strtolower($wallet->name);
            
            // Removed visual mask for negative cash so math remains consistent.
            
            // Driver logic: do not subtract ShopeePay debt from Total Saldo (Liquid wealth)
            if (str_contains($nameLower, 'shopee') && $wallet->balance < 0) {
                $totalBalance += 0;
            } else {
                $totalBalance += $wallet->balance;
            }
        }

        // Monthly summary (month of target date)
        $monthStart = $targetDate->copy()->startOfMonth();
        $monthEnd = $targetDate->copy()->endOfMonth();

        $monthlyTransactions = $user->transactions()
            ->whereDate('created_at', '>=', $monthStart)
            ->whereDate('created_at', '<=', $monthEnd)
            ->get();

        $monthlyMasuk = $monthlyTransactions->where('type', 'masuk')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount');
        $monthlyKeluar = $monthlyTransactions->where('type', 'keluar')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount');

        // Weekly summary (week of target date)
        $weekStart = $targetDate->copy()->startOfWeek();
        $weekEnd = $targetDate->copy()->endOfWeek();

        $weeklyTransactions = $user->transactions()
            ->whereDate('created_at', '>=', $weekStart)
            ->whereDate('created_at', '<=', $weekEnd)
            ->get();

        $weeklyMasuk = $weeklyTransactions->where('type', 'masuk')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount');
        $weeklyKeluar = $weeklyTransactions->where('type', 'keluar')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount');

        // 7-day chart data ending at target date
        $chartStart = $targetDate->copy()->subDays(6)->startOfDay();
        $chartEnd = $targetDateEnd;
        $chartTransactions = $user->transactions()
            ->whereDate('created_at', '>=', $chartStart)
            ->whereDate('created_at', '<=', $chartEnd)
            ->get();

        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $targetDate->copy()->subDays($i);
            $dateString = $date->format('Y-m-d');
            $displayDate = $date->translatedFormat('d M'); // e.g., "25 Jun"

            $dayTxs = $chartTransactions->filter(function($tx) use ($dateString) {
                return $tx->created_at->format('Y-m-d') === $dateString;
            });

            $chartData[] = [
                'date' => $displayDate,
                'masuk' => $dayTxs->where('type', 'masuk')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount'),
                'keluar' => $dayTxs->where('type', 'keluar')->whereNotIn('source', ['SISTEM_TRANSFER', 'MODAL'])->sum('amount'),
            ];
        }

        return response()->json([
            'today' => [
                'total_masuk' => $totalMasuk,
                'total_keluar' => $totalKeluar,
                'net' => $totalMasuk - $totalKeluar,
                'transaction_count' => $todayTransactions->count(),
                'top_categories' => $topCategories,
                'date' => $targetDate->format('Y-m-d'),
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

