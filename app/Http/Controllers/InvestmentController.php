<?php

namespace App\Http\Controllers;

use App\Models\Investment;
use App\Models\Balance;
use App\Models\DailyBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InvestmentController extends Controller
{
    // ============================================
    // ADD INVESTMENT
    // ============================================
    public function addInvestment(Request $request)
    {
        $request->validate([
            'add_investment' => 'required|numeric|min:0',
            'investment_category' => 'required|string',
        ]);

        $user  = Auth::user();
        $today = Carbon::now()->toDateString();

        DB::beginTransaction();

        try {

            $balance = Balance::with('currency')
                ->where('customerid', $user->customerid)
                ->first();

            if (!$balance) {
                return response()->json([
                    'message' => 'Opening balance not found. Please create opening balance first.'
                ], 404);
            }

            /**
             * Get or create today's daily balance
             */
            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $today)
                ->first();

            if (!$dailyBalance) {

                $previousBalance = DailyBalance::where('customerid', $user->customerid)
                    ->where('balance_date', '<', $today)
                    ->orderByDesc('balance_date')
                    ->first();

                $openingBalance = $previousBalance
                    ? $previousBalance->closing_balance
                    : $balance->opening_balance;

                $dailyBalance = DailyBalance::create([
                    'customerid'      => $user->customerid,
                    'balance_date'    => $today,
                    'opening_balance' => $openingBalance,
                    'total_credit'    => 0,
                    'total_debit'     => 0,
                    'closing_balance' => $openingBalance,
                    'currency_id'     => $balance->currency_id,
                ]);
            }

            // Check sufficient balance
            if ($dailyBalance->closing_balance < $request->add_investment) {
                return response()->json([
                    'message' => 'Insufficient balance.',
                    'available_balance' => (float) $dailyBalance->closing_balance,
                ], 400);
            }

            // Generate SN
            $sn = 'INV' . str_pad((Investment::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);

            // Total investment
            $existingTotal = Investment::where('customerid', $user->customerid)
                ->sum('add_investment');

            $newTotal = $existingTotal + $request->add_investment;

            // Store investment
            $investment = Investment::create([
                'sn'                  => $sn,
                'customerid'          => $user->customerid,
                'add_investment'      => $request->add_investment,
                'total_investment'    => $newTotal,
                'investment_category' => $request->investment_category,
                'created_date'        => $today,
                'updated_date'        => $today,
            ]);

            /**
             * Update daily balance
             */
            $dailyBalance->total_debit += $request->add_investment;

            $dailyBalance->closing_balance =
                $dailyBalance->opening_balance +
                $dailyBalance->total_credit -
                $dailyBalance->total_debit;

            $dailyBalance->save();

            DB::commit();

            return response()->json([
                'message' => 'Investment added successfully.',
                'data' => $investment,

                'currency' => [
                    'country'  => $balance->currency->country_name ?? null,
                    'currency' => $balance->currency->currency ?? null,
                    'symbol'   => $balance->currency->symbol ?? null,
                ],

                'daily_balance' => [
                    'date'            => $dailyBalance->balance_date,
                    'opening_balance' => (float) $dailyBalance->opening_balance,
                    'total_credit'    => (float) $dailyBalance->total_credit,
                    'total_debit'     => (float) $dailyBalance->total_debit,
                    'closing_balance' => (float) $dailyBalance->closing_balance,
                ]
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to add investment.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ============================================
    // UPDATE INVESTMENT
    // ============================================
    public function updateInvestment(Request $request, $sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $investment = Investment::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$investment) {
            return response()->json([
                'message' => 'Investment not found.'
            ], 404);
        }

        $request->validate([
            'add_investment' => 'required|numeric|min:0',
            'investment_category' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $balance = Balance::with('currency')
                ->where('customerid', $user->customerid)
                ->first();

            if (!$balance) {
                return response()->json([
                    'message' => 'Opening balance not found.'
                ], 404);
            }

            $oldAmount = (float) $investment->add_investment;
            $newAmount = (float) $request->add_investment;

            $transactionDate = Carbon::parse($investment->created_date)->toDateString();

            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $transactionDate)
                ->first();

            if ($dailyBalance) {

                $availableBalance =
                    $dailyBalance->closing_balance + $oldAmount;

                if ($newAmount > $availableBalance) {
                    return response()->json([
                        'message' => 'Insufficient balance.',
                        'available_balance' => (float) $availableBalance,
                    ], 400);
                }

                // Adjust debit
                $dailyBalance->total_debit =
                    $dailyBalance->total_debit - $oldAmount + $newAmount;

                $dailyBalance->closing_balance =
                    $dailyBalance->opening_balance +
                    $dailyBalance->total_credit -
                    $dailyBalance->total_debit;

                $dailyBalance->save();
            }

            // Recalculate total investment
            $totalInvestment = Investment::where('customerid', $user->customerid)
                ->where('sn', '!=', $sn)
                ->sum('add_investment') + $newAmount;

            // Update investment
            $investment->update([
                'add_investment'      => $newAmount,
                'investment_category' => $request->investment_category,
                'total_investment'    => $totalInvestment,
                'updated_date'        => now()->toDateString(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Investment updated successfully.',
                'data' => $investment,

                'currency' => [
                    'country'  => $balance->currency->country_name ?? null,
                    'currency' => $balance->currency->currency ?? null,
                    'symbol'   => $balance->currency->symbol ?? null,
                ],

                'daily_balance' => $dailyBalance ? [
                    'date'            => $dailyBalance->balance_date,
                    'opening_balance' => (float) $dailyBalance->opening_balance,
                    'total_credit'    => (float) $dailyBalance->total_credit,
                    'total_debit'     => (float) $dailyBalance->total_debit,
                    'closing_balance' => (float) $dailyBalance->closing_balance,
                ] : null,
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update investment.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ============================================
    // DELETE INVESTMENT
    // ============================================
    public function deleteInvestment($sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $investment = Investment::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$investment) {
            return response()->json([
                'message' => 'Investment not found.'
            ], 404);
        }

        DB::beginTransaction();

        try {

            $balance = Balance::with('currency')
                ->where('customerid', $user->customerid)
                ->first();

            if (!$balance) {
                return response()->json([
                    'message' => 'Opening balance not found.'
                ], 404);
            }

            $amount = (float) $investment->add_investment;

            $transactionDate = Carbon::parse($investment->created_date)->toDateString();

            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $transactionDate)
                ->first();

            if ($dailyBalance) {

                $dailyBalance->total_debit -= $amount;

                if ($dailyBalance->total_debit < 0) {
                    $dailyBalance->total_debit = 0;
                }

                $dailyBalance->closing_balance =
                    $dailyBalance->opening_balance +
                    $dailyBalance->total_credit -
                    $dailyBalance->total_debit;

                $dailyBalance->save();
            }

            // Delete investment
            $investment->delete();

            // Recalculate total investment
            $totalInvestment = Investment::where('customerid', $user->customerid)
                ->sum('add_investment');

            DB::commit();

            return response()->json([
                'message' => 'Investment deleted successfully.',
                'total_investment' => (float) $totalInvestment,

                'currency' => [
                    'country'  => $balance->currency->country_name ?? null,
                    'currency' => $balance->currency->currency ?? null,
                    'symbol'   => $balance->currency->symbol ?? null,
                ],

                'daily_balance' => $dailyBalance ? [
                    'date'            => $dailyBalance->balance_date,
                    'opening_balance' => (float) $dailyBalance->opening_balance,
                    'total_credit'    => (float) $dailyBalance->total_credit,
                    'total_debit'     => (float) $dailyBalance->total_debit,
                    'closing_balance' => (float) $dailyBalance->closing_balance,
                ] : null,
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to delete investment.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getDailyInvestmentGraph(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        // Last 30 days including today
        $endDate   = Carbon::today();
        $startDate = Carbon::today()->subDays(29);

        $investments = Investment::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('DATE(created_date) as date, SUM(add_investment) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $graphData = [];
        $dates     = [];
        $totals    = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {

            $dateStr = $date->format('Y-m-d');

            $total = isset($investments[$dateStr])
                ? (float) $investments[$dateStr]->total
                : 0;

            $graphData[] = [
                'date'  => $dateStr,
                'total' => $total,
            ];

            // Backward compatibility
            $dates[]  = $dateStr;
            $totals[] = $total;
        }

        /**
         * Currency info
         */
        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Daily investment graph fetched successfully.',

            'start_date' => $startDate->toDateString(),
            'end_date'   => $endDate->toDateString(),

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            // Preferred structure
            'data' => $graphData,

            // Optional backward compatibility
            'dates'  => $dates,
            'totals' => $totals,
        ]);
    }

    public function getMonthlyInvestmentGraph(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        // Last 12 months including current month
        $endDate   = Carbon::today()->endOfMonth();
        $startDate = Carbon::today()->subMonths(11)->startOfMonth();

        $investments = Investment::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('YEAR(created_date) as year, MONTH(created_date) as month, SUM(add_investment) as total')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $graphData = [];
        $months    = [];
        $totals    = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addMonth()) {

            $year  = $date->year;
            $month = $date->month;

            $investmentForMonth = $investments->first(function ($item) use ($year, $month) {
                return (int) $item->year === $year
                    && (int) $item->month === $month;
            });

            $label = $date->format('M Y'); // Aug 2026

            $total = $investmentForMonth
                ? (float) $investmentForMonth->total
                : 0;

            $graphData[] = [
                'month' => $label,
                'year'  => $year,
                'total' => $total,
            ];

            // Backward compatibility
            $months[] = $label;
            $totals[] = $total;
        }

        /**
         * Currency info
         */
        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Monthly investment graph fetched successfully.',

            'start_month' => $startDate->format('Y-m'),
            'end_month'   => $endDate->format('Y-m'),

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            // Preferred structure
            'data' => $graphData,

            // Optional backward compatibility
            'months' => $months,
            'totals' => $totals,
        ]);
    }

    public function getInvestmentList(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $query = Investment::where('customerid', $user->customerid);

        // Summary filter
        if ($request->summary == 'daily') {
            $query->whereDate('created_date', Carbon::today());

        } elseif ($request->summary == 'weekly') {
            $query->whereBetween('created_date', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ]);

        } elseif ($request->summary == 'monthly') {
            $query->whereMonth('created_date', Carbon::now()->month)
                ->whereYear('created_date', Carbon::now()->year);

        } elseif ($request->summary == 'yearly') {
            $query->whereYear('created_date', Carbon::now()->year);
        }

        // Category filter
        if ($request->investment_category) {
            $query->where('investment_category', $request->investment_category);
        }

        // Custom date filter
        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_date', [
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            ]);
        }

        // Fetch ascending for running total
        $data = $query->orderBy('created_date', 'ASC')->get();

        // Running total
        $runningTotal = 0;

        foreach ($data as $inv) {
            $runningTotal += (float) $inv->add_investment;
            $inv->total_investment = $runningTotal;
        }

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message'  => 'Investment list fetched successfully.',
            'summary'  => $request->summary,
            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],
            'data' => $data
        ]);
    }

    public function getTotalInvestment(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $filter = $request->filter ?? 'all';

        $query = Investment::where('customerid', $user->customerid);

        switch ($filter) {

            case 'daily':
                $query->whereDate('created_date', Carbon::today());
                break;

            case 'weekly':
                $query->whereBetween(DB::raw('DATE(created_date)'), [
                    Carbon::now()->startOfWeek()->toDateString(),
                    Carbon::now()->endOfWeek()->toDateString()
                ]);
                break;

            case 'monthly':
                $query->whereMonth('created_date', Carbon::now()->month)
                    ->whereYear('created_date', Carbon::now()->year);
                break;

            case 'yearly':
                $query->whereYear('created_date', Carbon::now()->year);
                break;

            default:
                break;
        }

        $totalInvestment = $query->sum('add_investment');

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Total investment fetched successfully.',

            'total_investment' => (float) $totalInvestment,

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],
        ]);
    }

    public function getCategoryWiseInvestments(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $query = Investment::where('customerid', $user->customerid);

        // Date range filter
        if ($request->has(['start_date', 'end_date']) &&
            $request->start_date && $request->end_date) {

            $query->whereBetween('created_date', [
                $request->start_date,
                $request->end_date
            ]);
        }

        // Time filter
        if ($request->has('filter_type') && $request->filter_type) {

            $today = now();

            switch ($request->filter_type) {

                case 'daily':
                    $query->whereDate('created_date', $today->toDateString());
                    break;

                case 'weekly':
                    $query->whereBetween('created_date', [
                        $today->copy()->startOfWeek(),
                        $today->copy()->endOfWeek()
                    ]);
                    break;

                case 'monthly':
                    $query->whereMonth('created_date', $today->month)
                        ->whereYear('created_date', $today->year);
                    break;

                case 'yearly':
                    $query->whereYear('created_date', $today->year);
                    break;
            }
        }

        $categoryInvestments = $query
            ->select('investment_category', DB::raw('SUM(add_investment) as total_amount'))
            ->groupBy('investment_category')
            ->orderBy('investment_category')
            ->get();

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Category-wise investments fetched successfully.',

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            'data' => $categoryInvestments,
        ]);
    }

}
