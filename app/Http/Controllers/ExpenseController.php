<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Expense;
use App\Models\Balance;
use App\Models\DailyBalance;

class ExpenseController extends Controller
{
    // =====================================
    // ADD EXPENSE
    // =====================================
    public function addExpense(Request $request)
    {
        $request->validate([
            'add_expenses'     => 'required|numeric|min:0',
            'expense_category' => 'required|string',
        ]);

        $user  = Auth::user();
        $today = Carbon::now()->toDateString();

        DB::beginTransaction();

        try {

            // Opening balance must exist
            $balance = Balance::where('customerid', $user->customerid)
                ->with('currency')
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

            // Check sufficient balance using daily balance
            if ($dailyBalance->closing_balance < $request->add_expenses) {
                return response()->json([
                    'message' => 'Insufficient balance to complete this expense.',
                    'available_balance' => (float) $dailyBalance->closing_balance,
                ], 400);
            }

            // Generate Expense SN
            $sn = 'EXP' . str_pad((Expense::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);

            // Calculate cumulative total expenses
            $existingTotal = Expense::where('customerid', $user->customerid)
                ->sum('add_expenses');

            $newTotal = $existingTotal + $request->add_expenses;

            /**
             * Store expense transaction
             */
            $expense = Expense::create([
                'sn'               => $sn,
                'customerid'       => $user->customerid,
                'add_expenses'     => $request->add_expenses,
                'total_expenses'   => $newTotal,
                'expense_category' => $request->expense_category,
                'created_date'     => $today,
                'updated_date'     => $today,
            ]);

            /**
             * Update daily balance (DEBIT)
             */
            $dailyBalance->total_debit += $request->add_expenses;

            $dailyBalance->closing_balance =
                $dailyBalance->opening_balance +
                $dailyBalance->total_credit -
                $dailyBalance->total_debit;

            $dailyBalance->save();

            DB::commit();

            return response()->json([
                'message' => 'Expense added successfully.',
                'data' => [
                    'id'               => $expense->id,
                    'sn'               => $expense->sn,
                    'customerid'       => $expense->customerid,
                    'add_expenses'     => (float) $expense->add_expenses,
                    'total_expenses'   => (float) $expense->total_expenses,
                    'expense_category' => $expense->expense_category,
                    'created_date'     => $expense->created_date,
                    'updated_date'     => $expense->updated_date,
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
                'message' => 'Failed to add expense.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =====================================
    // UPDATE EXPENSE
    // =====================================
    public function updateExpense(Request $request, $sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $expense = Expense::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$expense) {
            return response()->json([
                'message' => 'Expense not found.'
            ], 404);
        }

        $request->validate([
            'add_expenses'     => 'required|numeric|min:0',
            'expense_category' => 'required|string',
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

            // Original values
            $oldAmount       = (float) $expense->add_expenses;
            $newAmount       = (float) $request->add_expenses;
            $transactionDate = Carbon::parse($expense->created_date)->toDateString();

            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $transactionDate)
                ->first();

            if ($dailyBalance) {

                $availableBalance =
                    $dailyBalance->closing_balance + $oldAmount;

                if ($newAmount > $availableBalance) {
                    return response()->json([
                        'message' => 'Insufficient balance for updated expense.',
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

            // Update expense
            $expense->add_expenses     = $newAmount;
            $expense->expense_category = $request->expense_category;
            $expense->updated_date     = now()->toDateString();

            // Recalculate cumulative total expenses
            $totalExpenses = Expense::where('customerid', $user->customerid)
                ->where('id', '!=', $expense->id)
                ->sum('add_expenses') + $newAmount;

            $expense->total_expenses = $totalExpenses;

            $expense->save();

            DB::commit();

            return response()->json([
                'message' => 'Expense updated successfully.',

                'expense' => [
                    'id'               => $expense->id,
                    'sn'               => $expense->sn,
                    'customerid'       => $expense->customerid,
                    'add_expenses'     => (float) $expense->add_expenses,
                    'total_expenses'   => (float) $expense->total_expenses,
                    'expense_category' => $expense->expense_category,
                    'created_date'     => $expense->created_date,
                    'updated_date'     => $expense->updated_date,
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
                'message' => 'Failed to update expense.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =====================================
    // DELETE EXPENSE
    // =====================================
    public function deleteExpense($sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $expense = Expense::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$expense) {
            return response()->json([
                'message' => 'Expense not found.'
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

            $amount = (float) $expense->add_expenses;

            $transactionDate = Carbon::parse($expense->created_date)->toDateString();

            // Update daily balance
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

            // Delete expense
            $expense->delete();

            // Recalculate total expenses
            $newTotalExpenses = (float) Expense::where('customerid', $user->customerid)
                ->sum('add_expenses');

            DB::commit();

            return response()->json([
                'message' => 'Expense deleted successfully.',

                'total_expenses' => $newTotalExpenses,

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
                'message' => 'Failed to delete expense.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    // =====================================
    // FILTER EXPENSES (date, category, daily/weekly/monthly/yearly)
    // =====================================
    public function getExpenseLists(Request $request)
    {
    $user  = Auth::user();
    $query = Expense::where('customerid', $user->customerid);

    // Date range filter
    if ($request->has(['start_date', 'end_date']) && $request->start_date && $request->end_date) {
        $query->whereBetween('created_date', [
            $request->start_date,
            $request->end_date
        ]);
    }

    // Daily / Weekly / Monthly / Yearly filter
    if ($request->has('filter_type') && $request->filter_type !== null) {

        $filter = $request->filter_type;
        $today  = now();

        if ($filter === 'daily') {
            $query->whereDate('created_date', $today->toDateString());
        }

        if ($filter === 'weekly') {
            $query->whereBetween('created_date', [
                $today->copy()->startOfWeek()->toDateString(),
                $today->copy()->endOfWeek()->toDateString()
            ]);
        }

        if ($filter === 'monthly') {
            $query->whereYear('created_date', $today->year)
                  ->whereMonth('created_date', $today->month);
        }

        if ($filter === 'yearly') {
            $query->whereYear('created_date', $today->year);
        }
    }

    // Expense category filter
    if ($request->has('expense_category') && $request->expense_category !== null) {
        $query->where('expense_category', $request->expense_category);
    }

    // Sort ascending for running total
    $expenses = $query->orderBy('created_date', 'ASC')->get();

    // Running total
    $runningTotal = 0;
    foreach ($expenses as $exp) {
        $runningTotal += (float) $exp->add_expenses;
        $exp->total_expenses = $runningTotal;
    }

    // Currency
    $balance  = Balance::where('customerid', $user->customerid)
        ->with('currency')
        ->first();

    $currency = $balance->currency ?? null;

    return response()->json([
        'message'          => 'Expense list fetched successfully.',
        'filter_type'      => $request->filter_type ?? null,
        'start_date'       => $request->start_date ?? null,
        'end_date'         => $request->end_date ?? null,
        'expense_category' => $request->expense_category ?? null,
        'currency'         => $currency->currency ?? null,
        'symbol'           => $currency->symbol ?? null,
        'data'             => $expenses,
    ]);
}

    // =====================================
    // GET TOTAL EXPENSES
    // =====================================
    public function getTotalExpenses(Request $request)
    {
        $user = Auth::user();
    
        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }
    
        $filter = $request->filter ?? 'all';
    
        $query = Expense::where('customerid', $user->customerid);
    
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
    
        $totalExpenses = (float) $query->sum('add_expenses');
    
        /**
         * Currency from master balance
         */
        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();
    
        return response()->json([
            'message' => 'Total expenses fetched successfully.',
    
            'filter_type' => $filter,
    
            'total_expenses' => $totalExpenses,
    
            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],
        ]);
    }

    // =====================================
    // DAILY EXPENSE GRAPH
    // =====================================
    public function getDailyExpenseGraph(Request $request)
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

        $expenses = Expense::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('DATE(created_date) as date, SUM(add_expenses) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $graphData = [];
        $dates     = [];
        $totals    = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {

            $dateStr = $date->format('Y-m-d');

            $total = isset($expenses[$dateStr])
                ? (float) $expenses[$dateStr]->total
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
            'message' => 'Daily expense graph fetched successfully.',

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
    // MONTHLY EXPENSE GRAPH
    // =====================================
    public function getMonthlyExpenseGraph(Request $request)
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

        $expenses = Expense::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('YEAR(created_date) as year, MONTH(created_date) as month, SUM(add_expenses) as total')
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

            $expenseForMonth = $expenses->first(function ($item) use ($year, $month) {
                return (int) $item->year === $year
                    && (int) $item->month === $month;
            });

            $label = $date->format('M Y'); // Aug 2026

            $total = $expenseForMonth
                ? (float) $expenseForMonth->total
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
            'message' => 'Monthly expense graph fetched successfully.',

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

    // =====================================
    // CATEGORY-WISE EXPENSES
    // =====================================
    public function getCategoryWiseExpenses(Request $request)
    {
        $user = Auth::user();

        $query = Expense::where('customerid', $user->customerid);

        // Date range filter
        if ($request->has(['start_date', 'end_date']) && $request->start_date && $request->end_date) {
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

        // Group by category
        $categoryExpenses = $query
            ->select('expense_category', DB::raw('SUM(add_expenses) as total_amount'))
            ->groupBy('expense_category')
            ->orderBy('expense_category')
            ->get();

        // Currency
        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        $currency = $balance->currency ?? null;

        return response()->json([
            'message'  => 'Category-wise expenses fetched successfully.',
            'currency' => $currency->currency ?? null,
            'symbol'   => $currency->symbol ?? null,
            'data'     => $categoryExpenses,
        ]);
    }
}