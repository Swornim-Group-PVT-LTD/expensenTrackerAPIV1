<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Income;
use App\Models\Balance;
use App\Models\DailyBalance;
use App\Services\BalanceService;

class IncomeController extends Controller
{
    // =====================================
    // ADD INCOME
    // =====================================
   public function addIncome(Request $request)
    {
        $request->validate([
            'add_income'      => 'required|numeric|min:0',
            'income_category' => 'required|string',
        ]);

        $user  = Auth::user();
        $today = Carbon::now()->toDateString();

        DB::beginTransaction();

        try {

            // Opening balance must exist
            $balance = Balance::where('customerid', $user->customerid)
                ->with('currency')
                ->lockForUpdate()
                ->first();

            if (!$balance) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Opening balance not found. Please create opening balance first.'
                ], 404);
            }

            // Generate Income SN
            $sn = 'INC' . str_pad((Income::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);

            // Calculate cumulative total income
            $existingTotal = Income::where('customerid', $user->customerid)
                ->sum('add_income');

            $newTotal = $existingTotal + $request->add_income;

            /**
             * Get or create today's daily balance
             */
            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $today)
                ->first();

            if (!$dailyBalance) {

                // Get previous closing balance
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

            /**
             * Store income transaction
             */
            $income = Income::create([
                'sn'              => $sn,
                'customerid'      => $user->customerid,
                'add_income'      => $request->add_income,
                'total_income'    => $newTotal,
                'income_category' => $request->income_category,
                'created_date'    => $today,
                'updated_date'    => $today,
            ]);

            /**
             * Update daily balance
             */
            $dailyBalance->total_credit += $request->add_income;

            $dailyBalance->closing_balance =
                $dailyBalance->opening_balance +
                $dailyBalance->total_credit -
                $dailyBalance->total_debit;

            $dailyBalance->save();

            DB::commit();

            return response()->json([
                'message' => 'Income added successfully.',
                'data' => [
                    'id'              => $income->id,
                    'sn'              => $income->sn,
                    'customerid'      => $income->customerid,
                    'add_income'      => (float) $income->add_income,
                    'total_income'    => (float) $income->total_income,
                    'income_category' => $income->income_category,
                    'created_date'    => $income->created_date,
                    'updated_date'    => $income->updated_date,
                ],
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
                'message' => 'Failed to add income.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =====================================
    // LIST INCOMES (Filters: Date Range + Periods)
    // =====================================
   public function getIncomeList(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $query = Income::where('customerid', $user->customerid);

        /**
         * DATE FILTER
         */
        if ($request->filled(['start_date', 'end_date'])) {

            $startDate = Carbon::parse($request->start_date)->toDateString();
            $endDate   = Carbon::parse($request->end_date)->toDateString();

            $query->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate, $endDate]
            );

        } else {

            $today = Carbon::now();

            switch ($request->filter) {

                case 'daily':

                    $query->whereDate('created_date', $today->toDateString());

                    break;

                case 'weekly':

                    $startWeek = $today->copy()->startOfWeek()->toDateString();
                    $endWeek   = $today->copy()->endOfWeek()->toDateString();

                    $query->whereBetween(
                        DB::raw('DATE(created_date)'),
                        [$startWeek, $endWeek]
                    );

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

        /**
         * CATEGORY FILTER
         */
        $query->when(
            $request->filled('income_category'),
            fn ($q) => $q->where('income_category', $request->income_category)
        );

        /**
         * FETCH INCOMES
         */
        $incomes = $query->orderBy('created_date', 'ASC')
                        ->orderBy('id', 'ASC')
                        ->get();

        /**
         * CALCULATE CUMULATIVE TOTAL
         */
        $cumulativeTotal = 0;

        $data = $incomes->map(function ($income) use (&$cumulativeTotal) {

            $cumulativeTotal += (float) $income->add_income;

            return [
                'id'              => $income->id,
                'sn'              => $income->sn,
                'customerid'      => $income->customerid,
                'add_income'      => (float) $income->add_income,
                'total_income'    => $cumulativeTotal,
                'income_category' => $income->income_category,
                'created_date'    => $income->created_date,
                'updated_date'    => $income->updated_date,
            ];
        });

        /**
         * CURRENCY INFO FROM MASTER BALANCE
         */
        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message'         => 'Income list fetched successfully.',
            'start_date'      => $request->start_date ?? null,
            'end_date'        => $request->end_date ?? null,
            'filter_type'     => $request->filter ?? null,
            'income_category' => $request->income_category ?? null,

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            'total_records' => $data->count(),

            'total_income' => (float) $incomes->sum('add_income'),

            'data' => $data,
        ]);
    }

    // =====================================
    // GET TOTAL INCOME
    // =====================================
    public function getTotalIncome(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $filter = $request->filter ?? 'all';

        $query = Income::where('customerid', $user->customerid);

        $today = Carbon::now();

        switch ($filter) {

            case 'daily':

                $query->whereDate('created_date', $today->toDateString());

                break;

            case 'weekly':

                $startWeek = $today->copy()->startOfWeek()->toDateString();
                $endWeek   = $today->copy()->endOfWeek()->toDateString();

                $query->whereBetween(
                    DB::raw('DATE(created_date)'),
                    [$startWeek, $endWeek]
                );

                break;

            case 'monthly':

                $query->whereMonth('created_date', $today->month)
                    ->whereYear('created_date', $today->year);

                break;

            case 'yearly':

                $query->whereYear('created_date', $today->year);

                break;

            case 'all':
            default:
                // No filter
                break;
        }

        $totalIncome = (float) $query->sum('add_income');

        /**
         * Currency from master balance
         */
        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Total income fetched successfully.',

            'filter_type' => $filter,

            'total_income' => $totalIncome,

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],
        ]);
    }

    // =====================================
    // UPDATE INCOME
    // =====================================
   public function updateIncome(Request $request, $sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $request->validate([
            'add_income'      => 'required|numeric|min:0',
            'income_category' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $balance = Balance::with('currency')
                ->where('customerid', $user->customerid)
                ->lockForUpdate()
                ->first();

            if (!$balance) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Opening balance not found.'
                ], 404);
            }

            $income = Income::where('sn', $sn)
                ->where('customerid', $user->customerid)
                ->lockForUpdate()
                ->first();

            if (!$income) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Income not found.'
                ], 404);
            }

            // Original values
            $oldAmount       = (float) $income->add_income;
            $newAmount       = (float) $request->add_income;
            $transactionDate = Carbon::parse($income->created_date)->toDateString();

            /**
             * Update income row
             */
            $income->add_income      = $newAmount;
            $income->income_category = $request->income_category;
            $income->updated_date    = now()->toDateString();

            // Recalculate cumulative total income
            $totalIncome = Income::where('customerid', $user->customerid)
                ->where('id', '!=', $income->id)
                ->sum('add_income') + $newAmount;

            $income->total_income = $totalIncome;

            $income->save();

            /**
             * Update daily balance for transaction date
             */
            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $transactionDate)
                ->lockForUpdate()
                ->first();

            if ($dailyBalance) {

                // Adjust credit by removing old amount and adding new amount
                $dailyBalance->total_credit =
                    $dailyBalance->total_credit - $oldAmount + $newAmount;

                $dailyBalance->closing_balance =
                    $dailyBalance->opening_balance +
                    $dailyBalance->total_credit -
                    $dailyBalance->total_debit;

                $dailyBalance->save();
                app(BalanceService::class)->carryClosingBalanceForward($dailyBalance);
            }

            DB::commit();

            return response()->json([
                'message' => 'Income updated successfully.',

                'income' => [
                    'id'              => $income->id,
                    'sn'              => $income->sn,
                    'customerid'      => $income->customerid,
                    'add_income'      => (float) $income->add_income,
                    'total_income'    => (float) $income->total_income,
                    'income_category' => $income->income_category,
                    'created_date'    => $income->created_date,
                    'updated_date'    => $income->updated_date,
                ],

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
                'message' => 'Failed to update income.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =====================================
    // DELETE INCOME
    // =====================================
    public function deleteIncome($sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        DB::beginTransaction();

        try {

            $balance = Balance::with('currency')
                ->where('customerid', $user->customerid)
                ->lockForUpdate()
                ->first();

            if (!$balance) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Opening balance not found.'
                ], 404);
            }

            $income = Income::where('sn', $sn)
                ->where('customerid', $user->customerid)
                ->lockForUpdate()
                ->first();

            if (!$income) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Income not found.'
                ], 404);
            }

            $amount = (float) $income->add_income;

            // Transaction date
            $transactionDate = Carbon::parse($income->created_date)->toDateString();

            /**
             * Update daily balance
             */
            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $transactionDate)
                ->lockForUpdate()
                ->first();

            if ($dailyBalance) {

                $dailyBalance->total_credit -= $amount;

                if ($dailyBalance->total_credit < 0) {
                    $dailyBalance->total_credit = 0;
                }

                $dailyBalance->closing_balance =
                    $dailyBalance->opening_balance +
                    $dailyBalance->total_credit -
                    $dailyBalance->total_debit;

                $dailyBalance->save();
                app(BalanceService::class)->carryClosingBalanceForward($dailyBalance);
            }

            /**
             * Delete income
             */
            $income->delete();

            /**
             * Recalculate total income
             */
            $newTotalIncome = (float) Income::where('customerid', $user->customerid)
                ->sum('add_income');

            DB::commit();

            return response()->json([
                'message' => 'Income deleted successfully.',

                'total_income' => $newTotalIncome,

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
                'message' => 'Failed to delete income.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =====================================
    // DAILY INCOME GRAPH
    // =====================================
    public function getDailyIncomeGraph(Request $request)
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

        $incomes = Income::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('DATE(created_date) as date, SUM(add_income) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $graphData = [];
        $dates     = [];
        $totals    = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {

            $dateStr = $date->format('Y-m-d');

            $total = isset($incomes[$dateStr])
                ? (float) $incomes[$dateStr]->total
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
            'message' => 'Daily income graph fetched successfully.',

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

    // =====================================
    // MONTHLY INCOME GRAPH
    // =====================================
    public function getMonthlyIncomeGraph(Request $request)
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

        $incomes = Income::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('YEAR(created_date) as year, MONTH(created_date) as month, SUM(add_income) as total')
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

            $incomeForMonth = $incomes->first(function ($item) use ($year, $month) {
                return (int) $item->year === $year
                    && (int) $item->month === $month;
            });

            $label = $date->format('M Y'); // Aug 2026

            $total = $incomeForMonth
                ? (float) $incomeForMonth->total
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
            'message' => 'Monthly income graph fetched successfully.',

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
}
