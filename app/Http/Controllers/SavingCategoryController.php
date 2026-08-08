<?php

namespace App\Http\Controllers;

use App\Models\SavingCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class SavingCategoryController extends Controller
{
    // CREATE (CUSTOMER WISE)
    public function addSavingCategory(Request $request)
    {
        $validated = $request->validate([
            'static_value' => 'required|string',
            'saving_category' => 'required|string',
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
        $last = SavingCategory::where('customerid', $customerId)
            ->where('sn', 'LIKE', "SAVCAT{$customerSuffix}%")
            ->orderBy('sn', 'desc')
            ->first();

        if ($last) {
            $lastNum = (int) substr($last->sn, -6);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        // Build SN → SAVCAT + last2digits + 6-digit number
        $sn = "SAVCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Ensure SN uniqueness
        while (SavingCategory::where('sn', $sn)->exists()) {
            $nextNum++;
            $sn = "SAVCAT{$customerSuffix}" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        $today = Carbon::now()->toDateString();

        $validated['customerid'] = $customerId;
        $validated['sn'] = $sn;
        $validated['created_date'] = $today;
        $validated['updated_date'] = $today;

        $category = SavingCategory::create($validated);

        return response()->json([
            'message' => 'Saving category created successfully.',
            'data' => $category,
        ], 201);
    }

    // READ ALL (CUSTOMER WISE)
    public function getSavingCategoryList()
    {
        $user = Auth::user();

        $categories = SavingCategory::where('customerid', $user->customerid)
                        ->orderByDesc('created_date')
                        ->get();

        return response()->json([
            'message' => 'Saving category list fetched successfully.',
            'data' => $categories,
        ]);
    }

    // READ ONE (BY SN, CUSTOMER WISE)
    public function getSavingCategoryBySN($sn)
    {
        $user = Auth::user();

        $category = SavingCategory::where('sn', $sn)
                        ->where('customerid', $user->customerid)
                        ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Saving category not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Saving category fetched successfully.',
            'data' => $category,
        ]);
    }

    // UPDATE (CUSTOMER WISE USING SN)
    public function updateSavingCategory(Request $request, $id)
    {
        $user = Auth::user();

        $category = SavingCategory::where('id', $id)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Saving category not found.',
            ], 404);
        }

        $validated = $request->validate([
            'static_value'      => 'required|string|max:255',
            'saving_category'   => 'required|string|max:255',
            'additional_value1' => 'nullable|string|max:255',
            'additional_value2' => 'nullable|string|max:255',
            'additional_value3' => 'nullable|string|max:255',
            'additional_value4' => 'nullable|string|max:255',
        ]);

        $category->static_value = $validated['static_value'];
        $category->saving_category = $validated['saving_category'];
        $category->additional_value1 = $validated['additional_value1'] ?? null;
        $category->additional_value2 = $validated['additional_value2'] ?? null;
        $category->additional_value3 = $validated['additional_value3'] ?? null;
        $category->additional_value4 = $validated['additional_value4'] ?? null;
        $category->updated_date = Carbon::now()->toDateString();

        $category->save();
        $category->refresh();

        return response()->json([
            'message' => 'Saving category updated successfully.',
            'data' => $category,
        ], 200);
    }

    // DELETE (CUSTOMER WISE USING SN)
    public function destroySavingCategory($id)
    {
        $user = Auth::user();

        $category = SavingCategory::where('id', $id)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Saving category not found.',
            ], 404);
        }

        $category->delete();

        return response()->json([
            'message' => 'Saving category deleted successfully.',
        ], 200);
    }
}
