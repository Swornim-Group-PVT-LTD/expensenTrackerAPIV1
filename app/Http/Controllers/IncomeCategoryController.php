<?php

namespace App\Http\Controllers;

use App\Models\IncomeCategory;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class IncomeCategoryController extends Controller
{
    // CREATE (CUSTOMER WISE)
    public function addIncomeCategory(Request $request)
    {
        $validated = $request->validate([
            'static_value' => 'required|string',
            'income_category' => 'required|string',
            'additional_value1' => 'nullable|string',
            'additional_value2' => 'nullable|string',
            'additional_value3' => 'nullable|string',
            'additional_value4' => 'nullable|string',
        ]);

        $user = Auth::user();
        $customerId = $user->customerid;

        // Last 2 digits of customerid
        $customerSuffix = substr($customerId, -2);

        // Fetch last SN for this customer
        $last = IncomeCategory::where('customerid', $customerId)
            ->where('sn', 'LIKE', "INCCAT{$customerSuffix}%")
            ->orderBy('sn', 'desc')
            ->first();

        if ($last) {
            $lastNum = (int) substr($last->sn, -6);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        // Build SN → INCCAT + last2digits + 6 digit number
        $sn = "INCCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Ensure uniqueness
        while (IncomeCategory::where('sn', $sn)->exists()) {
            $nextNum++;
            $sn = "INCCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        $today = Carbon::now()->toDateString();

        $validated['customerid'] = $customerId;
        $validated['sn'] = $sn;
        $validated['created_date'] = $today;
        $validated['updated_date'] = $today;

        $incomeCategory = IncomeCategory::create($validated);

        return response()->json([
            'message' => 'Income category created successfully.',
            'data' => $incomeCategory,
        ], 201);
    }

    // READ ALL (CUSTOMER WISE)
    public function getIncomeCategoryLists()
    {
        $user = Auth::user();

        $categories = IncomeCategory::where('customerid', $user->customerid)
                        ->orderByDesc('created_date')
                        ->get();

        return response()->json([
            'message' => 'Income category list fetched successfully.',
            'data' => $categories
        ]);
    }

    // READ ONE (CUSTOMER WISE)
    public function getOneIncomeCateory($id)
    {
        $user = Auth::user();

        $category = IncomeCategory::where('id', $id)
                        ->where('customerid', $user->customerid)
                        ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Income category not found.'
            ], 404);
        }

        return response()->json([
            'message' => 'Income category fetched successfully.',
            'data' => $category
        ]);
    }

    // UPDATE (CUSTOMER WISE)
    public function updateIncomeCategory(Request $request, $id)
{
    $user = Auth::user();

    $category = IncomeCategory::where('id', $id)
        ->where('customerid', $user->customerid)
        ->first();

    if (!$category) {
        return response()->json([
            'message' => 'Income category not found.'
        ], 404);
    }

    $validated = $request->validate([
        'static_value'      => 'required|string|max:255',
        'income_category'   => 'required|string|max:255',
        'additional_value1' => 'nullable|string|max:255',
        'additional_value2' => 'nullable|string|max:255',
        'additional_value3' => 'nullable|string|max:255',
        'additional_value4' => 'nullable|string|max:255',
    ]);

    $category->static_value = $validated['static_value'];
    $category->income_category = $validated['income_category'];
    $category->additional_value1 = $validated['additional_value1'] ?? null;
    $category->additional_value2 = $validated['additional_value2'] ?? null;
    $category->additional_value3 = $validated['additional_value3'] ?? null;
    $category->additional_value4 = $validated['additional_value4'] ?? null;
    $category->updated_date = now()->toDateString();

    $category->save();
    $category->refresh();

    return response()->json([
        'message' => 'Income category updated successfully.',
        'data' => $category
    ], 200);
}

    // DELETE (CUSTOMER WISE)
   public function destroyIncomeCategory($id)
    {
        $user = Auth::user();

        $category = IncomeCategory::where('id', $id)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Income category not found.'
            ], 404);
        }

        $category->delete();

        return response()->json([
            'message' => 'Income category deleted successfully.'
        ], 200);
    }
}
