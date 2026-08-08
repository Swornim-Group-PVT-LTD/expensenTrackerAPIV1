<?php

namespace App\Http\Controllers;

use App\Models\InvestmentCategory;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class InvestmentCategoryController extends Controller
{
    // CREATE (CUSTOMER WISE)
    public function addInvestmentCategory(Request $request)
    {
        $validated = $request->validate([
            'static_value' => 'required|string',
            'investment_category' => 'required|string',
            'additional_value1' => 'nullable|string',
            'additional_value2' => 'nullable|string',
            'additional_value3' => 'nullable|string',
            'additional_value4' => 'nullable|string',
        ]);

        $user = Auth::user();
        $customerId = $user->customerid;

        // Last 2 digits of customerid
        $customerSuffix = substr($customerId, -2);

        // Find last SN for this customer
        $last = InvestmentCategory::where('customerid', $customerId)
            ->where('sn', 'LIKE', "INVCAT{$customerSuffix}%")
            ->orderBy('sn', 'desc')
            ->first();

        if ($last) {
            $lastNum = (int) substr($last->sn, -6);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        // Build SN: INVCAT + last2digits + 6-digit count
        $sn = "INVCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Ensure unique SN
        while (InvestmentCategory::where('sn', $sn)->exists()) {
            $nextNum++;
            $sn = "INVCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        $today = Carbon::now()->toDateString();

        $validated['customerid'] = $customerId;
        $validated['sn'] = $sn;
        $validated['created_date'] = $today;
        $validated['updated_date'] = $today;

        $category = InvestmentCategory::create($validated);

        return response()->json([
            'message' => 'Investment category created successfully.',
            'data' => $category,
        ], 201);
    }

    // READ ALL (CUSTOMER WISE)
    public function getInvestmentCategory()
    {
        $user = Auth::user();

        $categories = InvestmentCategory::where('customerid', $user->customerid)
            ->orderByDesc('created_date')
            ->get();

        return response()->json([
            'message' => 'Investment category list fetched successfully.',
            'data' => $categories
        ]);
    }

    // READ ONE BY SN (CUSTOMER WISE)
    public function getInvestmentCategoryById($sn)
    {
        $user = Auth::user();

        $category = InvestmentCategory::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Investment category not found.'
            ], 404);
        }

        return response()->json([
            'message' => 'Investment category fetched successfully.',
            'data' => $category
        ]);
    }

    // UPDATE (CUSTOMER WISE)
    public function updateInvestmentCategory(Request $request, $id)
    {
        $user = Auth::user();

        $category = InvestmentCategory::where('id', $id)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Investment category not found.'
            ], 404);
        }

        $validated = $request->validate([
            'static_value'         => 'required|string|max:255',
            'investment_category'  => 'required|string|max:255',
            'additional_value1'    => 'nullable|string|max:255',
            'additional_value2'    => 'nullable|string|max:255',
            'additional_value3'    => 'nullable|string|max:255',
            'additional_value4'    => 'nullable|string|max:255',
        ]);

        $category->static_value = $validated['static_value'];
        $category->investment_category = $validated['investment_category'];
        $category->additional_value1 = $validated['additional_value1'] ?? null;
        $category->additional_value2 = $validated['additional_value2'] ?? null;
        $category->additional_value3 = $validated['additional_value3'] ?? null;
        $category->additional_value4 = $validated['additional_value4'] ?? null;
        $category->updated_date = now()->toDateString();

        $category->save();
        $category->refresh();

        return response()->json([
            'message' => 'Investment category updated successfully.',
            'data' => $category
        ], 200);
    }

    // DELETE (CUSTOMER WISE)
    public function deleteInvestmentCategory($id)
    {
        $user = Auth::user();

        $category = InvestmentCategory::where('id', $id)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Investment category not found.'
            ], 404);
        }

        $category->delete();

        return response()->json([
            'message' => 'Investment category deleted successfully.'
        ], 200);
    }
}
