<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Income;
use App\Models\Expense;
use App\Models\Saving;
use App\Models\Investment;
use App\Models\DailyBalance;
use App\Services\BalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class BalanceController extends Controller
{
    // ============================
    // ADD CUSTOMER BALANCE
    // ============================
    public function addBalance(Request $request)
    {
        $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'currency_id'     => 'required|exists:currencies,id',
        ]);

        $user = Auth::user();

        DB::beginTransaction();

        try {

            // Check if opening balance already exists
            $existingBalance = Balance::where('customerid', $user->customerid)->first();

            if ($existingBalance) {

                $existingBalance->load('currency');

                DB::rollBack();

                return response()->json([
                    'message' => 'Opening balance already exists.',
                    'data' => [
                        'sn'              => $existingBalance->sn,
                        'customerid'      => $existingBalance->customerid,
                        'opening_balance' => $existingBalance->opening_balance,
                        'currency' => [
                            'country'  => $existingBalance->currency->country_name ?? null,
                            'currency' => $existingBalance->currency->currency ?? null,
                            'symbol'   => $existingBalance->currency->symbol ?? null,
                        ]
                    ]
                ], 400);
            }

            $today = now()->toDateString();

            $sn = 'BAL' . str_pad((Balance::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);

            // MASTER BALANCE
            $balance = Balance::create([
                'sn'              => $sn,
                'customerid'      => $user->customerid,
                'opening_balance' => $request->opening_balance,
                'currency_id'     => $request->currency_id,
                'created_date'    => $today,
                'updated_date'    => $today,
            ]);

            // FIRST DAILY SNAPSHOT
            DailyBalance::create([
                'customerid'      => $user->customerid,
                'balance_date'    => $today,
                'opening_balance' => $request->opening_balance,
                'total_credit'    => 0,
                'total_debit'     => 0,
                'closing_balance' => $request->opening_balance,
                'currency_id'     => $request->currency_id,
            ]);

            DB::commit();

            $balance->load('currency');

            return response()->json([
                'message' => 'Opening balance created successfully.',
                'data' => [
                    'sn'              => $balance->sn,
                    'customerid'      => $balance->customerid,
                    'opening_balance' => $balance->opening_balance,
                    'currency' => [
                        'country'  => $balance->currency->country_name ?? null,
                        'currency' => $balance->currency->currency ?? null,
                        'symbol'   => $balance->currency->symbol ?? null,
                    ],
                    'daily_balance' => [
                        'date'            => $today,
                        'opening_balance' => $request->opening_balance,
                        'credit'          => 0,
                        'debit'           => 0,
                        'closing_balance' => $request->opening_balance,
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create opening balance.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    // ============================
    // GET CUSTOMER BALANCE
    // ============================
    public function getBalance(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        if (!$balance) {
            return response()->json([
                'message' => 'Opening balance not found.'
            ], 404);
        }

        $service = app(BalanceService::class);

        $dailyBalance = $service->getOrCreateTodayBalance($user->customerid);

        return response()->json([
            'message' => 'Balance retrieved successfully',
            'data' => [
                'customerid' => $balance->customerid,
                'sn' => $balance->sn,

                'master_opening_balance' => $balance->opening_balance,

                'date' => Carbon::parse($dailyBalance->balance_date)->format('Y-m-d'),
                'opening_balance' => $dailyBalance->opening_balance,
                'total_credit' => $dailyBalance->total_credit,
                'total_debit' => $dailyBalance->total_debit,
                'closing_balance' => $dailyBalance->closing_balance,

                'currency' => [
                    'country'  => $balance->currency->country_name ?? null,
                    'currency' => $balance->currency->currency ?? null,
                    'symbol'   => $balance->currency->symbol ?? null,
                ]
            ]
        ]);
    }

    // ============================
    // GET MONTHLY REMAINING BALANCE FOR GRAPH
    // ============================
    public function getMonthlyRemainingBalanceGraph(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $year = $request->year ?? now()->year;

        $graphData = [];

        for ($month = 1; $month <= 12; $month++) {

            // Total income
            $totalIncome = Income::where('customerid', $user->customerid)
                ->whereYear('created_date', $year)
                ->whereMonth('created_date', $month)
                ->sum('add_income');

            // Total expense
            $expense = Expense::where('customerid', $user->customerid)
                ->whereYear('created_date', $year)
                ->whereMonth('created_date', $month)
                ->sum('add_expenses');

            $savingTransfers = Saving::where('customerid', $user->customerid)
                ->whereYear('created_date', $year)
                ->whereMonth('created_date', $month)
                ->whereNotNull('transfer_key')
                ->sum('add_saving');

            // Total saving
            // Deduct ONLY where want_to_deduct_from_balance = 1
            $saving = Saving::where('customerid', $user->customerid)
                ->whereYear('created_date', $year)
                ->whereMonth('created_date', $month)
                ->where('want_to_deduct_from_balance', 1)
                ->sum('add_saving');

            // Total investment
            $investment = Investment::where('customerid', $user->customerid)
                ->whereYear('created_date', $year)
                ->whereMonth('created_date', $month)
                ->sum('add_investment');

            // Total outgoing
            $totalDebit = $expense + $saving + $investment;

            // Remaining balance
            $remainingBalance = $totalIncome + $savingTransfers - $totalDebit;

            $graphData[] = [
                'month' => Carbon::create($year, $month, 1)->format('F'),
                'year' => $year,
                'income' => (float) $totalIncome,
                'expenses' => (float) $expense,
                'saving_transferred' => (float) $savingTransfers,
                'saving_deducted' => (float) $saving,
                'investment' => (float) $investment,
                'total_debit' => (float) $totalDebit,
                'remaining_balance' => (float) $remainingBalance,
            ];
        }

        return response()->json([
            'message' => 'Monthly remaining balance graph retrieved successfully',
            'year' => $year,
            'rule' => [
                'saving_deducted_when_flag' => 1,
                'saving_not_deducted_when_flag' => 0,
            ],
            'data' => $graphData,
        ]);
    }
        // ============================
    // ACCOUNT STATEMENT
    // ============================

    public function getAccountStatement(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $customerId = $user->customerid;

        // =========================================================
        // DATE RANGE
        // =========================================================

        $fromDate = $request->from_date
            ? Carbon::parse($request->from_date)->toDateString()
            : now()->toDateString();

        $toDate = $request->to_date
            ? Carbon::parse($request->to_date)->toDateString()
            : now()->toDateString();

        // Validate date range
        if ($fromDate > $toDate) {
            return response()->json([
                'message' => 'from_date cannot be greater than to_date'
            ], 422);
        }

        // =========================================================
        // MASTER BALANCE
        // =========================================================

        $balance = Balance::with('currency')
            ->where('customerid', $customerId)
            ->first();

        if (!$balance) {
            return response()->json([
                'message' => 'Opening balance not found.'
            ], 404);
        }

        // =========================================================
        // OPENING BALANCE
        // =========================================================

        $dailyBalance = DailyBalance::where('customerid', $customerId)
            ->where('balance_date', $fromDate)
            ->first();

        if ($dailyBalance) {

            $openingBalance = (float) $dailyBalance->opening_balance;

        } else {

            $previousBalance = DailyBalance::where('customerid', $customerId)
                ->where('balance_date', '<', $fromDate)
                ->orderByDesc('balance_date')
                ->first();

            if ($previousBalance) {

                $openingBalance = (float) $previousBalance->closing_balance;

            } else {

                $openingBalance = (float) $balance->opening_balance;
            }
        }

        // =========================================================
        // DATE FILTER
        // =========================================================

        $applyDateFilter = function ($query) use ($fromDate, $toDate) {
            $query->whereBetween('created_date', [
                $fromDate,
                $toDate
            ]);
        };

        // =========================================================
        // INCOME
        // =========================================================

        $incomes = Income::where('customerid', $customerId)
            ->when(true, $applyDateFilter)
            ->get()
            ->map(function ($item) {

                return [
                    'date' => Carbon::parse($item->created_date)->format('Y-m-d H:i:s'),
                    'type' => 'Income',
                    'description' => $item->income_category,
                    'credit' => (float) $item->add_income,
                    'debit' => 0,
                ];
            });

        // =========================================================
        // EXPENSE
        // =========================================================

        $expenses = Expense::where('customerid', $customerId)
            ->when(true, $applyDateFilter)
            ->get()
            ->map(function ($item) {

                return [
                    'date' => Carbon::parse($item->created_date)->format('Y-m-d H:i:s'),
                    'type' => 'Expense',
                    'description' => $item->expense_category,
                    'credit' => 0,
                    'debit' => (float) $item->add_expenses,
                ];
            });

        // =========================================================
        // SAVING
        // =========================================================

        $savings = Saving::where('customerid', $customerId)
            ->when(true, $applyDateFilter)
            ->where(function ($query) {
                $query->where('want_to_deduct_from_balance', true)->orWhereNotNull('transfer_key');
            })
            ->orderBy('id')
            ->get()
            ->map(function (Saving $item) {
                return [
                    'date' => Carbon::parse($item->created_date)->format('Y-m-d H:i:s'),
                    'type' => $item->is_transfer ? 'Saving Transfer Balance' : 'Saving',
                    'description' => $item->is_transfer && $item->remarks !== $item->saving_category
                        ? $item->saving_category . ' - ' . $item->remarks
                        : $item->saving_category,
                    'credit' => $item->is_transfer ? (float) $item->add_saving : 0,
                    'debit' => $item->is_transfer ? 0 : (float) $item->add_saving,
                ];
            });

        // =========================================================
        // INVESTMENT
        // =========================================================

        $investments = Investment::where('customerid', $customerId)
            ->when(true, $applyDateFilter)
            ->get()
            ->map(function ($item) {

                return [
                    'date' => Carbon::parse($item->created_date)->format('Y-m-d H:i:s'),
                    'type' => 'Investment',
                    'description' => $item->investment_category,
                    'credit' => 0,
                    'debit' => (float) $item->add_investment,
                ];
            });

        // =========================================================
        // MERGE TRANSACTIONS
        // =========================================================
        //
        // Sort by actual created date and time so records appear
        // in the exact sequence they were entered.
        // =========================================================

        $transactions = collect()
            ->merge($incomes)
            ->merge($expenses)
            ->merge($savings)
            ->merge($investments)
            ->sortBy(function ($item) {
                return Carbon::parse($item['date'])->timestamp;
            })
            ->values();

        // =========================================================
        // RUNNING BALANCE
        // =========================================================

        $runningBalance = $openingBalance;

        $transactions = $transactions->map(function ($item) use (&$runningBalance) {

            $runningBalance += $item['credit'];
            $runningBalance -= $item['debit'];

            $item['balance'] = $runningBalance;

            return $item;
        });

        // =========================================================
        // RESPONSE
        // =========================================================

        return response()->json([

            'message' => 'Account statement retrieved successfully',

            'currency' => [
                'country' => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol' => $balance->currency->symbol ?? null,
            ],

            'from_date' => $fromDate,

            'to_date' => $toDate,

            'opening_balance' => $openingBalance,

            'closing_balance' => $runningBalance,

            'total_credit' => (float) $transactions->sum('credit'),

            'total_debit' => (float) $transactions->sum('debit'),

            'data' => $transactions
        ]);
    }
    
    public function getDailyBalance(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $filter = $request->filter ?? 'daily';

        $query = DailyBalance::where('customerid', $user->customerid);

        $today = Carbon::today();

        switch ($filter) {

            case 'daily':
                $query->whereDate('balance_date', $today);
                break;

            case 'weekly':
                $query->whereBetween('balance_date', [
                    $today->copy()->startOfWeek()->toDateString(),
                    $today->copy()->endOfWeek()->toDateString()
                ]);
                break;

            case 'monthly':
                $query->whereYear('balance_date', $today->year)
                    ->whereMonth('balance_date', $today->month);
                break;

            case 'all':
            default:
                // no filter
                break;
        }

        $balances = $query->orderBy('balance_date', 'ASC')->get();

        if ($balances->isEmpty()) {
            return response()->json([
                'message' => 'No balance records found.',
                'filter' => $filter
            ], 404);
        }

        // Currency from first record
        $currency = DailyBalance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        // Summary
        $summary = [
            'opening_balance' => (float) $balances->first()->opening_balance,
            'total_credit'    => (float) $balances->sum('total_credit'),
            'total_debit'     => (float) $balances->sum('total_debit'),
            'closing_balance' => (float) $balances->last()->closing_balance,
        ];

        // Detailed records
        $data = $balances->map(function ($balance) {
            return [
                'date'            => $balance->balance_date,
                'opening_balance' => (float) $balance->opening_balance,
                'total_credit'    => (float) $balance->total_credit,
                'total_debit'     => (float) $balance->total_debit,
                'closing_balance' => (float) $balance->closing_balance,
            ];
        });

        return response()->json([
            'message' => 'Daily balance fetched successfully.',
            'filter'  => $filter,

            'currency' => [
                'country'  => $currency->currency->country_name ?? null,
                'currency' => $currency->currency->currency ?? null,
                'symbol'   => $currency->currency->symbol ?? null,
            ],

            'summary' => $summary,

            'total_records' => $data->count(),

            'data' => $data,
        ]);
    }
}
