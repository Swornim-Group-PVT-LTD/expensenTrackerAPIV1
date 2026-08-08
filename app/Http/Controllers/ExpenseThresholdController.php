<?php

namespace App\Http\Controllers;

use App\Models\ExpenseThreshold;
use App\Models\Balance;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ExpenseThresholdController extends Controller
{
    // LIST: get all thresholds of logged-in user
    public function getAllThresholds()
    {
        $user = Auth::user();

        $thresholds = ExpenseThreshold::where('customerid', $user->customerid)
            ->orderBy('create_date', 'desc')
            ->get();

        // Get currency info
        $balance  = Balance::with('currency')->where('customerid', $user->customerid)->first();
        $currency = $balance->currency ?? null;

        return response()->json([
            'message'  => 'Expense thresholds fetched successfully.',
            'currency' => $currency->currency ?? null,
            'symbol'   => $currency->symbol ?? null,
            'data'     => $thresholds,
        ], 200);
    }

    // CREATE: add threshold (user-wise)
    public function addExpenseThreshold(Request $request)
    {
        $request->validate([
            'expense_threshold_amount' => 'required|numeric|min:0',
            'frequency'                => 'required|string',
            'isEnable'                 => 'boolean',
        ]);

        $user = Auth::user();

        if (!$user || !$user->customerid) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        $today = Carbon::now()->toDateString();

        // Generate SN with EXPTH prefix
        $nextId = ExpenseThreshold::max('id') + 1;
        $sn = 'EXPTH' . str_pad($nextId, 6, '0', STR_PAD_LEFT);

        $threshold = ExpenseThreshold::create([
            'sn'                       => $sn,
            'customerid'               => $user->customerid,
            'expense_threshold_amount' => $request->expense_threshold_amount,
            'frequency'                => $request->frequency,
            'isEnable'                 => $request->input('isEnable', true),
            'create_date'              => $today,
        ]);

        // Get currency info
        $balance  = Balance::with('currency')->where('customerid', $user->customerid)->first();
        $currency = $balance->currency ?? null;

        return response()->json([
            'message'  => 'Expense threshold created successfully.',
            'data'     => $threshold,
            'currency' => $currency->currency ?? null,
            'symbol'   => $currency->symbol ?? null,
        ], 201);
    }

    // READ: single threshold by SN
    public function getExpenseThreshold($sn)
    {
        $user = Auth::user();

        $threshold = ExpenseThreshold::where('customerid', $user->customerid)
            ->where('sn', $sn)
            ->firstOrFail();

        // Get currency info
        $balance  = Balance::with('currency')->where('customerid', $user->customerid)->first();
        $currency = $balance->currency ?? null;

        return response()->json([
            'message'  => 'Expense threshold fetched successfully.',
            'data'     => $threshold,
            'currency' => $currency->currency ?? null,
            'symbol'   => $currency->symbol ?? null,
        ], 200);
    }

    // UPDATE: update threshold by SN
    public function updateExpenseThreshold(Request $request, $sn)
    {
        $request->validate([
            'expense_threshold_amount' => 'required|numeric|min:0',
            'frequency'                => 'required|string',
            'isEnable'                 => 'boolean',
        ]);

        $user  = Auth::user();
        $today = Carbon::now()->toDateString();

        $threshold = ExpenseThreshold::where('customerid', $user->customerid)
            ->where('sn', $sn)
            ->firstOrFail();

        $threshold->update([
            'expense_threshold_amount' => $request->expense_threshold_amount,
            'frequency'                => $request->frequency,
            'isEnable'                 => $request->has('isEnable') ? $request->isEnable : $threshold->isEnable,
            'update_date'              => $today,
        ]);

        // Get currency info
        $balance  = Balance::with('currency')->where('customerid', $user->customerid)->first();
        $currency = $balance->currency ?? null;

        return response()->json([
            'message'  => 'Expense threshold updated successfully.',
            'data'     => $threshold,
            'currency' => $currency->currency ?? null,
            'symbol'   => $currency->symbol ?? null,
        ], 200);
    }

    // DELETE: delete threshold by SN
    public function deleteExpenseThreshold($sn)
    {
        $user = Auth::user();

        $threshold = ExpenseThreshold::where('customerid', $user->customerid)
            ->where('sn', $sn)
            ->firstOrFail();

        $threshold->delete();

        // Get currency info
        $balance  = Balance::with('currency')->where('customerid', $user->customerid)->first();
        $currency = $balance->currency ?? null;

        return response()->json([
            'message'  => 'Expense threshold deleted successfully.',
            'currency' => $currency->currency ?? null,
            'symbol'   => $currency->symbol ?? null,
        ], 200);
    }

   // COMPARE: monthly/yearly expense vs active threshold with warning and exceeded amount
    public function compareThreshold(Request $request)
    {
        $request->validate([
            'filter' => 'required|in:monthly,yearly',
        ]);

        $user = Auth::user();
        $filter = $request->filter;
        $today = Carbon::now();

        // Get active threshold for the filter type
        $threshold = ExpenseThreshold::where('customerid', $user->customerid)
            ->where('frequency', ucfirst($filter)) // assuming frequency stored as 'Monthly' or 'Yearly'
            ->where('isEnable', true)
            ->first();

        if (!$threshold) {
            return response()->json([
                'message' => "No active $filter threshold found."
            ], 404);
        }

        // Get total expenses based on filter
        $query = Expense::where('customerid', $user->customerid);

        if ($filter === 'monthly') {
            $query->whereYear('created_date', $today->year)
                ->whereMonth('created_date', $today->month);
        } else { // yearly
            $query->whereYear('created_date', $today->year);
        }

        $totalExpenses = $query->sum('add_expenses');

        // Calculate remaining and exceeded amounts
        $remainingAmount = $threshold->expense_threshold_amount - $totalExpenses;
        $remainingAmount = $remainingAmount > 0 ? $remainingAmount : 0;

        $exceededAmount = $totalExpenses - $threshold->expense_threshold_amount;
        $exceededAmount = $exceededAmount > 0 ? $exceededAmount : 0;

        // Check if user has spent 70% or more of the threshold
        $percentUsed = ($totalExpenses / $threshold->expense_threshold_amount) * 100;
        $warningMessage = null;

        if ($percentUsed >= 70 && $percentUsed < 100) {
            $warningMessage = "You have used " . round($percentUsed, 2) . "% of your $filter expense threshold. Consider monitoring your spending.";
        } elseif ($percentUsed >= 100) {
            $warningMessage = "You have exceeded your $filter expense threshold by " . round($exceededAmount, 2) . ". Please adjust your spending!";
        }

        // Get currency info
        $balance  = Balance::with('currency')->where('customerid', $user->customerid)->first();
        $currency = $balance->currency ?? null;

        return response()->json([
            'message'           => ucfirst($filter) . ' expense comparison fetched successfully.',
            'filter'            => $filter,
            'threshold_amount'  => $threshold->expense_threshold_amount,
            'total_expenses'    => $totalExpenses,
            'remaining_amount'  => $remainingAmount,
            'exceeded_amount'   => $exceededAmount,
            'percent_used'      => round($percentUsed, 2),
            'warning'           => $warningMessage,
            'currency'          => $currency->currency ?? null,
            'symbol'            => $currency->symbol ?? null,
            'threshold_sn'      => $threshold->sn,
        ]);
    }
}
