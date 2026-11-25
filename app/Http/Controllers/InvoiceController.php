<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    /**
     * Add Invoice
     */
    public function addInvoice(Request $request)
    {
        $validated = $request->validate([
            'invoice_number'       => 'nullable|string|max:50',
            'purchase_order'       => 'nullable|string|max:100',
            'logo'                 => 'nullable|image|mimes:jpg,jpeg,png|max:5120',

            'company_details'      => 'nullable|string',
            'bill_to'              => 'nullable|string',

            'currency_id'          => 'required|exists:currencies,id',

            'invoice_date'         => 'required|date',
            'due_date'             => 'nullable|date|after_or_equal:invoice_date',

            'notes_payment_terms'  => 'nullable|string',
            'bank_account_details' => 'nullable|string',

            'tax_percentage'       => 'nullable|numeric|min:0',
            'discount_type'        => 'nullable|in:flat,percent',
            'discount_amount'      => 'nullable|numeric|min:0',
            'shipping_fee'         => 'nullable|numeric|min:0',

            'items'                => 'required|array|min:1',
            'items.*.item_description' => 'required|string',
            'items.*.unit_cost'         => 'required|numeric|min:0',
            'items.*.quantity'          => 'required|numeric|min:0.01',
        ]);

        $user = Auth::user();

        $totals = $this->calculateTotals($validated);

        DB::beginTransaction();

        try {

            /*
             * Generate Invoice SN
             * Example: INV000001
             */
            $sn = 'INV' . str_pad(
                (Invoice::max('id') ?? 0) + 1,
                6,
                '0',
                STR_PAD_LEFT
            );

            /*
             * Upload Logo
             */
            $logoPath = null;

            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')
                    ->store('invoices/logos', 'public');
            }

            /*
             * Current Date
             */
            $today = now()->toDateString();

            /*
             * Create Invoice
             */
            $invoice = Invoice::create([
                'sn' => $sn,

                'customerid' =>
                    $user->customerid,

                'invoice_number' =>
                    $request->invoice_number,

                'purchase_order' =>
                    $request->purchase_order,

                'logo' =>
                    $logoPath,

                'company_details' =>
                    $request->company_details,

                'bill_to' =>
                    $request->bill_to,

                'currency_id' =>
                    $request->currency_id,

                'invoice_date' =>
                    $request->invoice_date,

                'due_date' =>
                    $request->due_date,

                'notes_payment_terms' =>
                    $request->notes_payment_terms,

                'bank_account_details' =>
                    $request->bank_account_details,

                ...$totals,

                'created_date' =>
                    $today,

                'updated_date' =>
                    $today,
            ]);

            /*
             * Create Invoice Items
             */
            foreach ($request->items as $item) {

                $amount =
                    $item['unit_cost'] *
                    $item['quantity'];

                $invoice->items()->create([
                    'item_description' =>
                        $item['item_description'],

                    'unit_cost' =>
                        $item['unit_cost'],

                    'quantity' =>
                        $item['quantity'],

                    'amount' =>
                        $amount,
                ]);
            }

            DB::commit();

            /*
             * Load Relationships
             */
            $invoice->load([
                'items',
                'currency'
            ]);

            return response()->json([
                'message' =>
                    'Invoice created successfully.',

                'data' => [
                    'sn' =>
                        $invoice->sn,

                    'customerid' =>
                        $invoice->customerid,

                    'invoice_number' =>
                        $invoice->invoice_number,

                    'purchase_order' =>
                        $invoice->purchase_order,

                    'logo' =>
                        $invoice->logo,

                    'company_details' =>
                        $invoice->company_details,

                    'bill_to' =>
                        $invoice->bill_to,

                    'invoice_date' =>
                        $invoice->invoice_date,

                    'due_date' =>
                        $invoice->due_date,

                    'currency' => [
                        'country' =>
                            $invoice->currency->country_name ?? null,

                        'currency' =>
                            $invoice->currency->currency ?? null,

                        'symbol' =>
                            $invoice->currency->symbol ?? null,
                    ],

                    'items' =>
                        $invoice->items,

                    'subtotal' =>
                        $invoice->subtotal,

                    'tax_percentage' =>
                        $invoice->tax_percentage,

                    'discount_amount' =>
                        $invoice->discount_amount,

                    'discount_type' =>
                        $invoice->discount_type,

                    'shipping_fee' =>
                        $invoice->shipping_fee,

                    'total_amount' =>
                        $invoice->total_amount,

                    'notes_payment_terms' =>
                        $invoice->notes_payment_terms,

                    'bank_account_details' =>
                        $invoice->bank_account_details,
                ]
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' =>
                    'Failed to create invoice.',

                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get Invoice List
     */
    public function getInvoiceList()
    {
        $user = Auth::user();

        try {

            $invoices = Invoice::with([
                'items',
                'currency'
            ])
                ->where(
                    'customerid',
                    $user->customerid
                )
                ->orderBy('id', 'desc')
                ->get();

            return response()->json([
                'message' =>
                    'Invoice list retrieved successfully.',

                'data' =>
                    $invoices
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'message' =>
                    'Failed to retrieve invoice list.',

                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get Single Invoice
     */
    public function getInvoice($sn)
    {
        $user = Auth::user();

        try {

            $invoice = Invoice::with([
                'items',
                'currency'
            ])
                ->where('sn', $sn)
                ->where(
                    'customerid',
                    $user->customerid
                )
                ->first();

            if (!$invoice) {

                return response()->json([
                    'message' =>
                        'Invoice not found.'
                ], 404);
            }

            return response()->json([
                'message' =>
                    'Invoice retrieved successfully.',

                'data' =>
                    $invoice
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'message' =>
                    'Failed to retrieve invoice.',

                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }


    /**
     * Update Invoice
     */
    public function updateInvoice(
        Request $request,
        $sn
    ) {
        $user = Auth::user();

        $validated = $request->validate([
            'invoice_number' =>
                'nullable|string|max:50',

            'purchase_order' =>
                'nullable|string|max:100',

            'logo' =>
                'nullable|image|mimes:jpg,jpeg,png|max:5120',

            'company_details' =>
                'nullable|string',

            'bill_to' =>
                'nullable|string',

            'currency_id' =>
                'required|exists:currencies,id',

            'invoice_date' =>
                'required|date',

            'due_date' =>
                'nullable|date|after_or_equal:invoice_date',

            'notes_payment_terms' =>
                'nullable|string',

            'bank_account_details' =>
                'nullable|string',

            'tax_percentage' =>
                'nullable|numeric|min:0',

            'discount_type' =>
                'nullable|in:flat,percent',

            'discount_amount' =>
                'nullable|numeric|min:0',

            'shipping_fee' =>
                'nullable|numeric|min:0',

            'status' => ['sometimes', 'required', Rule::in(Invoice::STATUSES)],

            'items' =>
                'required|array|min:1',

            'items.*.item_description' =>
                'required|string',

            'items.*.unit_cost' =>
                'required|numeric|min:0',

            'items.*.quantity' =>
                'required|numeric|min:0.01',
        ]);

        $totals = $this->calculateTotals($validated);

        DB::beginTransaction();

        try {

            $invoice = Invoice::where('sn', $sn)
                ->where(
                    'customerid',
                    $user->customerid
                )
                ->first();

            if (!$invoice) {

                DB::rollBack();

                return response()->json([
                    'message' =>
                        'Invoice not found.'
                ], 404);
            }

            /*
             * Replace Logo
             */
            if ($request->hasFile('logo')) {

                if ($invoice->logo) {

                    Storage::disk('public')
                        ->delete($invoice->logo);
                }

                $invoice->logo =
                    $request->file('logo')
                        ->store(
                            'invoices/logos',
                            'public'
                        );
            }

            $today = now()->toDateString();

            /*
             * Update Invoice
             */
            if (array_key_exists('status', $validated)) {
                $invoice->status = $validated['status'];
            }

            $invoice->update([
                'invoice_number' =>
                    $request->invoice_number,

                'purchase_order' =>
                    $request->purchase_order,

                'company_details' =>
                    $request->company_details,

                'bill_to' =>
                    $request->bill_to,

                'currency_id' =>
                    $request->currency_id,

                'invoice_date' =>
                    $request->invoice_date,

                'due_date' =>
                    $request->due_date,

                'notes_payment_terms' =>
                    $request->notes_payment_terms,

                'bank_account_details' =>
                    $request->bank_account_details,

                ...$totals,

                'updated_date' =>
                    $today,
            ]);

            /*
             * Delete Existing Items
             */
            $invoice->items()->delete();

            /*
             * Add Updated Items
             */
            foreach ($request->items as $item) {

                $invoice->items()->create([
                    'item_description' =>
                        $item['item_description'],

                    'unit_cost' =>
                        $item['unit_cost'],

                    'quantity' =>
                        $item['quantity'],

                    'amount' =>
                        $item['unit_cost']
                        * $item['quantity'],
                ]);
            }

            DB::commit();

            $invoice->load([
                'items',
                'currency'
            ]);

            return response()->json([
                'message' =>
                    'Invoice updated successfully.',

                'data' =>
                    $invoice
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' =>
                    'Failed to update invoice.',

                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }


    private function calculateTotals(array $validated): array
    {
        $subtotal = array_sum(array_map(
            fn (array $item) => $item['unit_cost'] * $item['quantity'],
            $validated['items'],
        ));
        $type = $validated['discount_type'] ?? null;
        $discount = (float) ($validated['discount_amount'] ?? 0);
        $taxPercentage = (float) ($validated['tax_percentage'] ?? 0);
        $shipping = (float) ($validated['shipping_fee'] ?? 0);

        // Requests without a discount type retain the legacy flat discount calculation.
        if ($type === null) {
            $total = $subtotal + ($subtotal * $taxPercentage / 100) - $discount + $shipping;
        } else {
            $subtotal = round($subtotal, 2);
            $discount = round($discount, 2);
            $taxPercentage = round($taxPercentage, 2);
            $shipping = round($shipping, 2);

            if ($type === 'percent' && $discount > 100) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'Percentage discount cannot exceed 100%.',
                ]);
            }
            if ($type === 'flat' && $discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'Flat discount cannot exceed the subtotal.',
                ]);
            }

            $appliedDiscount = $type === 'percent' ? round($subtotal * $discount / 100, 2) : $discount;
            $taxable = $subtotal - $appliedDiscount;
            $total = round($taxable + round($taxable * $taxPercentage / 100, 2) + $shipping, 2);
        }

        return [
            'subtotal' => $subtotal,
            'tax_percentage' => $taxPercentage,
            'discount_type' => $type,
            'discount_amount' => $discount,
            'shipping_fee' => $shipping,
            'total_amount' => $total,
        ];
    }

    /**
     * Update only the invoice status.
     */
    public function updateInvoiceStatus(Request $request, $sn)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Invoice::STATUSES)],
        ]);

        $invoice = Invoice::where('sn', $sn)
            ->where('customerid', Auth::user()->customerid)
            ->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $invoice->update([
            'status' => $validated['status'],
            'updated_date' => now()->toDateString(),
        ]);

        return response()->json([
            'message' => 'Invoice status updated successfully.',
            'data' => [
                'sn' => $invoice->sn,
                'status' => $invoice->status,
            ],
        ]);
    }

    /**
     * Delete Invoice
     */
    public function deleteInvoice($sn)
    {
        $user = Auth::user();

        DB::beginTransaction();

        try {

            $invoice = Invoice::where('sn', $sn)
                ->where(
                    'customerid',
                    $user->customerid
                )
                ->first();

            if (!$invoice) {

                DB::rollBack();

                return response()->json([
                    'message' =>
                        'Invoice not found.'
                ], 404);
            }

            /*
             * Delete Logo
             */
            if ($invoice->logo) {

                Storage::disk('public')
                    ->delete($invoice->logo);
            }

            /*
             * Delete Invoice
             *
             * Invoice items are deleted
             * through cascadeOnDelete()
             */
            $invoice->delete();

            DB::commit();

            return response()->json([
                'message' =>
                    'Invoice deleted successfully.'
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' =>
                    'Failed to delete invoice.',

                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }
}