<?php

namespace App\Http\Controllers;

use App\Models\Saving;
use App\Models\Balance;
use App\Models\DailyBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SavingController extends Controller
{
    // =========================================
    // ADD SAVING
    // =========================================
    public function addSaving(Request $request)
    {
        $request->validate([
            'add_saving' => 'required|numeric|min:0',
            'saving_category' => 'required|string',
            'want_to_deduct_from_balance' => 'required|boolean',
        ]);

        $user  = Auth::user();
        $today = Carbon::now()->toDateString();

        DB::beginTransaction();

        try {

            $balance = Balance::with('currency')
                ->where('customerid', $user->customerid)
                ->lockForUpdate()
                ->first();

            if (!$balance) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Opening balance not found. Please create opening balance first.'
                ], 404);
            }

            /**
             * Get or create today's daily balance
             */
            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $today)
                ->lockForUpdate()
                ->first();

            if (!$dailyBalance) {

                $previousBalance = DailyBalance::where('customerid', $user->customerid)
                    ->where('balance_date', '<', $today)
                    ->orderByDesc('balance_date')
                    ->lockForUpdate()
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

            // Deduct from balance if requested
            if ($request->want_to_deduct_from_balance) {

                if ($dailyBalance->closing_balance < $request->add_saving) {
                    return response()->json([
                        'message' => 'Insufficient balance.',
                        'available_balance' => (float) $dailyBalance->closing_balance,
                    ], 400);
                }
            }

            // Generate SN
            $sn = 'SAV' . str_pad((Saving::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);

            // Total Saving Calculation
            $existingTotal = Saving::where('customerid', $user->customerid)
                ->sum('add_saving');

            $newTotal = $existingTotal + $request->add_saving;

            // Store Saving
            $saving = Saving::create([
                'sn'                           => $sn,
                'customerid'                   => $user->customerid,
                'add_saving'                   => $request->add_saving,
                'total_saving'                 => $newTotal,
                'saving_category'              => $request->saving_category,
                'want_to_deduct_from_balance'  => $request->want_to_deduct_from_balance,
                'created_date'                 => $today,
                'updated_date'                 => $today,
            ]);

            /**
             * Update daily balance if deduction requested
             */
            if ($request->want_to_deduct_from_balance) {

                $dailyBalance->total_debit += $request->add_saving;

                $dailyBalance->closing_balance =
                    $dailyBalance->opening_balance +
                    $dailyBalance->total_credit -
                    $dailyBalance->total_debit;

                $dailyBalance->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Saving added successfully.',
                'data' => [
                    'id'                           => $saving->id,
                    'sn'                           => $saving->sn,
                    'customerid'                   => $saving->customerid,
                    'add_saving'                   => (float) $saving->add_saving,
                    'total_saving'                 => (float) $saving->total_saving,
                    'saving_category'              => $saving->saving_category,
                    'want_to_deduct_from_balance'  => (bool) $saving->want_to_deduct_from_balance,
                    'created_date'                 => $saving->created_date,
                    'updated_date'                 => $saving->updated_date,
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
                'message' => 'Failed to add saving.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================
    // UPDATE SAVING
    // =========================================
    public function updateSaving(Request $request, $sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $saving = Saving::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$saving) {
            return response()->json([
                'message' => 'Saving not found.'
            ], 404);
        }

        $request->validate([
            'add_saving' => 'required|numeric|min:0',
            'saving_category' => 'required|string',
            'want_to_deduct_from_balance' => 'required|boolean',
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

            $oldAmount = (float) $saving->add_saving;
            $newAmount = (float) $request->add_saving;

            $oldFlag = (bool) $saving->want_to_deduct_from_balance;
            $newFlag = (bool) $request->want_to_deduct_from_balance;

            $transactionDate = Carbon::parse($saving->created_date)->toDateString();

            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $transactionDate)
                ->lockForUpdate()
                ->first();

            if ($dailyBalance) {

                // Restore old deduction
                if ($oldFlag) {
                    $dailyBalance->total_debit -= $oldAmount;
                }

                // Check new deduction
                if ($newFlag) {

                    $availableBalance =
                        $dailyBalance->opening_balance +
                        $dailyBalance->total_credit -
                        $dailyBalance->total_debit;

                    if ($newAmount > $availableBalance) {
                        return response()->json([
                            'message' => 'You tried to save more than the available balance.',
                            'availableBalance' => (float) $availableBalance
                        ], 400);
                    }

                    $dailyBalance->total_debit += $newAmount;
                }

                $dailyBalance->closing_balance =
                    $dailyBalance->opening_balance +
                    $dailyBalance->total_credit -
                    $dailyBalance->total_debit;

                $dailyBalance->save();
            }

            // Recalculate total saving
            $totalSaving = Saving::where('customerid', $user->customerid)
                ->where('sn', '!=', $sn)
                ->sum('add_saving') + $newAmount;

            // Update saving
            $saving->update([
                'add_saving'                  => $newAmount,
                'saving_category'             => $request->saving_category,
                'want_to_deduct_from_balance' => $newFlag,
                'total_saving'                => $totalSaving,
                'updated_date'                => now()->toDateString(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Saving updated successfully.',
                'data' => $saving,
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
                'message' => 'Failed to update saving.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================
    // DELETE SAVING
    // =========================================
    public function deleteSaving($sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $saving = Saving::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$saving) {
            return response()->json([
                'message' => 'Saving not found.'
            ], 404);
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

            $transactionDate = Carbon::parse($saving->created_date)->toDateString();

            $dailyBalance = DailyBalance::where('customerid', $user->customerid)
                ->where('balance_date', $transactionDate)
                ->lockForUpdate()
                ->first();

            // Restore deducted amount
            if ($saving->want_to_deduct_from_balance && $dailyBalance) {

                $dailyBalance->total_debit -= $saving->add_saving;

                if ($dailyBalance->total_debit < 0) {
                    $dailyBalance->total_debit = 0;
                }

                $dailyBalance->closing_balance =
                    $dailyBalance->opening_balance +
                    $dailyBalance->total_credit -
                    $dailyBalance->total_debit;

                $dailyBalance->save();
            }

            $saving->delete();

            $totalSaving = Saving::where('customerid', $user->customerid)
                ->sum('add_saving');

            DB::commit();

            return response()->json([
                'message' => 'Saving deleted successfully.',
                'total_saving' => (float) $totalSaving,
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
                'message' => 'Failed to delete saving.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getSavingLists(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $query = Saving::where('customerid', $user->customerid);

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
        if ($request->saving_category) {
            $query->where('saving_category', $request->saving_category);
        }

        // Custom date range
        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_date', [
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            ]);
        }

        $data = $query->orderBy('created_date', 'ASC')->get();

        // Running total
        $runningTotal = 0;

        foreach ($data as $sav) {
            $runningTotal += (float) $sav->add_saving;
            $sav->total_saving = $runningTotal;
        }

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Saving list fetched successfully.',
            'summary' => $request->summary,

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            'data' => $data
        ]);
    }

    public function getTotalSaving(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $filter = $request->filter ?? 'all';

        $query = Saving::where('customerid', $user->customerid);

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

        $totalSaving = $query->sum('add_saving');

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Total saving fetched successfully.',

            'total_saving' => (float) $totalSaving,

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],
        ]);
    }
    
    public function getDailySavingGraph(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $endDate   = Carbon::today();
        $startDate = Carbon::today()->subDays(29);

        $savings = Saving::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('DATE(created_date) as date, SUM(add_saving) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $graphData = [];
        $dates = [];
        $totals = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {

            $dateStr = $date->format('Y-m-d');

            $total = isset($savings[$dateStr])
                ? (float) $savings[$dateStr]->total
                : 0;

            $graphData[] = [
                'date'  => $dateStr,
                'total' => $total,
            ];

            $dates[] = $dateStr;
            $totals[] = $total;
        }

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Daily saving graph fetched successfully.',

            'start_date' => $startDate->toDateString(),
            'end_date'   => $endDate->toDateString(),

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            'data' => $graphData,

            'dates'  => $dates,
            'totals' => $totals,
        ]);
    }

    public function getMonthlySavingGraph(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $endDate   = Carbon::today()->endOfMonth();
        $startDate = Carbon::today()->subMonths(11)->startOfMonth();

        $savings = Saving::where('customerid', $user->customerid)
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [$startDate->toDateString(), $endDate->toDateString()]
            )
            ->selectRaw('YEAR(created_date) as year, MONTH(created_date) as month, SUM(add_saving) as total')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $graphData = [];
        $months = [];
        $totals = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addMonth()) {

            $year  = $date->year;
            $month = $date->month;

            $savingForMonth = $savings->first(function ($item) use ($year, $month) {
                return (int) $item->year === $year
                    && (int) $item->month === $month;
            });

            $label = $date->format('M Y');

            $total = $savingForMonth
                ? (float) $savingForMonth->total
                : 0;

            $graphData[] = [
                'month' => $label,
                'year'  => $year,
                'total' => $total,
            ];

            $months[] = $label;
            $totals[] = $total;
        }

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Monthly saving graph fetched successfully.',

            'start_month' => $startDate->format('Y-m'),
            'end_month'   => $endDate->format('Y-m'),

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            'data' => $graphData,

            'months' => $months,
            'totals' => $totals,
        ]);
    }

    public function getCategoryWiseSavings(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Request is not authorized'
            ], 401);
        }

        $query = Saving::where('customerid', $user->customerid);

        // Date range filter
        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_date', [
                $request->start_date,
                $request->end_date
            ]);
        }

        // Time filter
        if ($request->filter_type) {

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

        $categorySavings = $query
            ->select('saving_category', DB::raw('SUM(add_saving) as total_amount'))
            ->groupBy('saving_category')
            ->orderBy('saving_category')
            ->get();

        $balance = Balance::with('currency')
            ->where('customerid', $user->customerid)
            ->first();

        return response()->json([
            'message' => 'Category-wise savings fetched successfully.',

            'currency' => [
                'country'  => $balance->currency->country_name ?? null,
                'currency' => $balance->currency->currency ?? null,
                'symbol'   => $balance->currency->symbol ?? null,
            ],

            'data' => $categorySavings,
        ]);
    }

}