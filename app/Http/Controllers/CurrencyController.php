<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CurrencyController extends Controller
{
    // ============================
    // ADD CURRENCY
    // ============================
    public function addCurrency(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'country_name'  => 'required|string|max:255',
            'currency_code' => 'required|string|max:10',
            'symbol'        => 'required|string|max:10',
        ]);

        $currency = Currency::create([
            'country_name' => $request->country_name,
            'currency'     => $request->currency_code,   // DB column
            'symbol'       => $request->symbol,          // DB column
            'customerid'   => $user->customerid ?? null,
        ]);

        return response()->json([
            'message' => 'Currency added successfully',
            'data'    => $currency,
        ]);
    }

    // ============================
    // GET ALL CURRENCIES
    // ============================
    public function getCurrencies()
    {
        $user = Auth::user();

        $currencies = Currency::where('customerid', $user->customerid)
            ->orderBy('sn', 'asc')
            ->get();

        return response()->json($currencies);
    }

    // ============================
    // UPDATE CURRENCY
    // ============================
    public function updateCurrency(Request $request, $sn)
    {
        $user = Auth::user();

        $currency = Currency::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        $request->validate([
            'country_name'  => 'required|string|max:255',
            'currency_code' => 'required|string|max:10',
            'symbol'        => 'required|string|max:10',
        ]);

        $currency->update([
            'country_name' => $request->country_name,
            'currency'     => $request->currency_code,   // DB column
            'symbol'       => $request->symbol,          // DB column
        ]);

        return response()->json([
            'message' => 'Currency updated successfully.',
            'data'    => $currency,
        ]);
    }

    // ============================
    // DELETE CURRENCY
    // ============================
    public function deleteCurrency($sn)
    {
        $user = Auth::user();

        $currency = Currency::where('sn', $sn)
            ->where('customerid', $user->customerid)
            ->first();

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        $currency->delete();

        return response()->json(['message' => 'Currency deleted successfully.']);
    }
}