<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExpenseCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class ExpenseCategoryController extends Controller
{
    // CREATE
    public function addExpenseCategory(Request $request)
    {
        $validated = $request->validate([
            'static_value' => 'required|string',
            'expense_category' => 'required|string',
            'additional_value1' => 'nullable|string',
            'additional_value2' => 'nullable|string',
            'additional_value3' => 'nullable|string',
            'additional_value4' => 'nullable|string',
        ]);

        $user = Auth::user();
        $customerId = $user->customerid;

        // Get last 2 digits of customerid
        $customerSuffix = substr($customerId, -2);

        // Fetch last SN for this customer
        $last = ExpenseCategory::where('customerid', $customerId)
            ->where('sn', 'LIKE', "EXPCAT{$customerSuffix}%")
            ->orderBy('sn', 'desc')
            ->first();

        if ($last) {
            // Extract running number (last 6 digits)
            $lastNum = (int) substr($last->sn, -6);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        // Build SN: EXPCAT + last2digits + 6–digit running number
        $sn = "EXPCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Check uniqueness
        while (ExpenseCategory::where('sn', $sn)->exists()) {
            $nextNum++;
            $sn = "EXPCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        $today = Carbon::now()->toDateString();

        $validated['customerid'] = $customerId;
        $validated['sn'] = $sn;
        $validated['created_date'] = $today;
        $validated['updated_date'] = $today;

        $category = ExpenseCategory::create($validated);

        return response()->json([
            'message' => 'Expense Category created successfully.',
            'data' => $category
        ], 201);
    }

    // READ ALL (CUSTOMER WISE)
    public function getExpenseCategory()
    {
        $user = Auth::user();

        $categories = ExpenseCategory::where('customerid', $user->customerid)
                        ->orderByDesc('created_date')
                        ->get();

        return response()->json([
            'message' => 'Expense category list fetched successfully.',
            'data' => $categories
        ]);
    }

    // READ ONE (CUSTOMER WISE)
    public function getExpenseCategoryById($id)
    {
        $user = Auth::user();

        $category = ExpenseCategory::where('id', $id)
                        ->where('customerid', $user->customerid)
                        ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Expense category not found.'
            ], 404);
        }

        return response()->json([
            'message' => 'Expense category fetched successfully.',
            'data' => $category
        ]);
    }

    // UPDATE (CUSTOMER WISE)
    public function updateExpenseCategory(Request $request, $id)
    {
        $user = Auth::user();

        $category = ExpenseCategory::where('id', $id)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Expense category not found.'
            ], 404);
        }

        $validated = $request->validate([
            'static_value'      => 'required|string|max:255',
            'expense_category'  => 'required|string|max:255',
            'additional_value1' => 'nullable|string|max:255',
            'additional_value2' => 'nullable|string|max:255',
            'additional_value3' => 'nullable|string|max:255',
            'additional_value4' => 'nullable|string|max:255',
        ]);

        $category->static_value = $validated['static_value'];
        $category->expense_category = $validated['expense_category'];
        $category->additional_value1 = $validated['additional_value1'] ?? null;
        $category->additional_value2 = $validated['additional_value2'] ?? null;
        $category->additional_value3 = $validated['additional_value3'] ?? null;
        $category->additional_value4 = $validated['additional_value4'] ?? null;
        $category->updated_date = now()->toDateString();

        $category->save();
        $category->refresh();

        return response()->json([
            'message' => 'Expense category updated successfully.',
            'data' => $category
        ], 200);
    }

    // DELETE (CUSTOMER WISE)
    public function deleteExpenseCategory($id)
    {
        $user = Auth::user();

        $category = ExpenseCategory::where('id', $id)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Expense category not found.'
            ], 404);
        }

        $category->delete();

        return response()->json([
            'message' => 'Expense category deleted successfully.'
        ], 200);
    }
        // ============================
    // UPDATE DAILY BALANCE
    // ============================
    public static function updateDailyBalance($customerId, $date = null)
    {
        $date = $date
            ? Carbon::parse($date)->toDateString()
            : now()->toDateString();

        // Master opening balance
        $masterBalance = Balance::where('customerid', $customerId)->first();

        $initialOpeningBalance = $masterBalance?->add_opening_balance ?? 0;

        // Previous day's closing balance
        $previousBalance = DailyBalance::where('customerid', $customerId)
            ->where('balance_date', '<', $date)
            ->orderByDesc('balance_date')
            ->first();

        $openingBalance = $previousBalance
            ? $previousBalance->closing_balance
            : $initialOpeningBalance;

        // Total income for the date
        $totalCredit = Income::where('customerid', $customerId)
            ->whereDate('created_date', $date)
            ->sum('add_income');

        // Total expense for the date
        $expense = Expense::where('customerid', $customerId)
            ->whereDate('created_date', $date)
            ->sum('add_expenses');

        // Total saving for the date
        $saving = Saving::where('customerid', $customerId)
            ->whereDate('created_date', $date)
            ->sum('add_saving');

        // Total investment for the date
        $investment = Investment::where('customerid', $customerId)
            ->whereDate('created_date', $date)
            ->sum('add_investment');

        // Debit = expense + saving + investment
        $totalDebit = $expense + $saving + $investment;

        // Closing balance
        $closingBalance = $openingBalance + $totalCredit - $totalDebit;

        // Currency from master balance
        $currencyId = $masterBalance?->currency_id;

        return DailyBalance::updateOrCreate(
            [
                'customerid'   => $customerId,
                'balance_date' => $date,
            ],
            [
                'opening_balance' => $openingBalance,
                'total_credit'    => $totalCredit,
                'total_debit'     => $totalDebit,
                'closing_balance' => $closingBalance,
                'currency_id'     => $currencyId,
            ]
        );
    }
}