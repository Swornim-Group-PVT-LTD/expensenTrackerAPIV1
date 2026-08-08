<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Income;
use App\Models\Expense;
use App\Models\Saving;
use App\Models\Investment;

class DashboardController extends Controller
{
    /**
     * Get totals for dashboard: Income, Expenses, Saving, Investment
     * Supports filters: daily, weekly, monthly, yearly
     */
    public function getDashboardData(Request $request)
    {
        $user = Auth::user();
        $filter = $request->filter ?? 'all';

        $data = [];

        // Helper closure to calculate total for a model
        $calculateTotal = function($model, $column) use ($user, $filter) {
            $query = $model::where('customerid', $user->customerid);

            switch ($filter) {
                case 'daily':
                    $query->whereDate('created_date', now());
                    break;
                case 'weekly':
                    $query->whereBetween('created_date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'monthly':
                    $query->whereMonth('created_date', now()->month)
                          ->whereYear('created_date', now()->year);
                    break;
                case 'yearly':
                    $query->whereYear('created_date', now()->year);
                    break;
                default:
                    // No filter, sum all
                    break;
            }

            return $query->sum($column);
        };

        // Calculate totals
        $data['income'] = $calculateTotal(Income::class, 'add_income');
        $data['expenses'] = $calculateTotal(Expense::class, 'add_expenses');
        $data['saving'] = $calculateTotal(Saving::class, 'add_saving');
        $data['investment'] = $calculateTotal(Investment::class, 'add_investment');

        return response()->json([
            'message' => 'Dashboard data fetched successfully.',
            'data' => $data,
        ]);
    }
}
