<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Str;

use App\Models\Expense;
use App\Models\ExpenseDocument;
use App\Models\Balance;
use App\Models\DailyBalance;

class ExpenseController extends Controller
{
    // ========================================================================
    // ADD EXPENSE
    // ========================================================================

    public function addExpense(Request $request)
    {
        $request->validate([
            'add_expenses' => 'required|numeric|min:0',
            'expense_category' => 'required|string|max:255',

            // Receipt is optional
            'upload_receipt' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],

            // Multiple documents are optional
            'upload_doc' => [
                'nullable',
                'array',
            ],

            'upload_doc.*' => [
                'file',
                'mimes:jpg,jpeg,png,pdf,doc,docx',
                'max:10240',
            ],
        ]);

        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Request is not authorized.',
            ], 401);
        }

        $customerid = $user->customerid;
        $today = Carbon::now()->toDateString();

        DB::beginTransaction();

        try {

            // ----------------------------------------------------------------
            // Get master balance
            // ----------------------------------------------------------------

            $balance = Balance::where('customerid', $customerid)
                ->with('currency')
                ->first();

            if (!$balance) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Opening balance not found. Please create opening balance first.',
                ], 404);
            }

            // ----------------------------------------------------------------
            // Get or create today's daily balance
            // ----------------------------------------------------------------

            $dailyBalance = DailyBalance::where('customerid', $customerid)
                ->where('balance_date', $today)
                ->first();

            if (!$dailyBalance) {

                // Get previous day's closing balance
                $previousBalance = DailyBalance::where(
                        'customerid',
                        $customerid
                    )
                    ->where('balance_date', '<', $today)
                    ->orderByDesc('balance_date')
                    ->first();

                $openingBalance = $previousBalance
                    ? $previousBalance->closing_balance
                    : $balance->opening_balance;

                $dailyBalance = DailyBalance::create([
                    'customerid' => $customerid,
                    'balance_date' => $today,
                    'opening_balance' => $openingBalance,
                    'total_credit' => 0,
                    'total_debit' => 0,
                    'closing_balance' => $openingBalance,
                    'currency_id' => $balance->currency_id,
                ]);
            }

            // ----------------------------------------------------------------
            // Check sufficient balance
            // ----------------------------------------------------------------

            $expenseAmount = (float) $request->add_expenses;

            if ((float) $dailyBalance->closing_balance < $expenseAmount) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance to complete this expense.',
                    'available_balance' => (float) $dailyBalance->closing_balance,
                ], 400);
            }

            // ----------------------------------------------------------------
            // Generate Expense SN
            // ----------------------------------------------------------------

            do {
                $sn = 'EXP' . strtoupper(Str::random(8));
            } while (Expense::where('sn', $sn)->exists());

            // ----------------------------------------------------------------
            // Calculate cumulative total expenses
            // ----------------------------------------------------------------

            $existingTotal = Expense::where(
                'customerid',
                $customerid
            )->sum('add_expenses');

            $newTotal = (float) $existingTotal + $expenseAmount;

            // ----------------------------------------------------------------
            // Create Expense
            // ----------------------------------------------------------------

            $expense = Expense::create([
                'sn' => $sn,
                'customerid' => $customerid,
                'add_expenses' => $expenseAmount,
                'total_expenses' => $newTotal,
                'expense_category' => $request->expense_category,

                // Receipt initially null
                'upload_receipt' => null,

                'created_date' => now(),
                'updated_date' => now(),
            ]);

            // ----------------------------------------------------------------
            // Upload Receipt - OPTIONAL
            // ----------------------------------------------------------------

            if ($request->hasFile('upload_receipt')) {

                $receipt = $request->file('upload_receipt');

                $receiptDirectory = public_path('upload_receipt');

                if (!File::exists($receiptDirectory)) {
                    File::makeDirectory(
                        $receiptDirectory,
                        0755,
                        true
                    );
                }

                $receiptName = time() . '_' .
                    Str::random(10) . '.' .
                    $receipt->getClientOriginalExtension();

                $receipt->move(
                    $receiptDirectory,
                    $receiptName
                );

                $expense->upload_receipt =
                    'upload_receipt/' . $receiptName;

                $expense->updated_date = now();

                $expense->save();
            }

            // ----------------------------------------------------------------
            // Update Daily Balance - DEBIT
            // ----------------------------------------------------------------

            $dailyBalance->total_debit =
                (float) $dailyBalance->total_debit + $expenseAmount;

            $dailyBalance->closing_balance =
                (float) $dailyBalance->opening_balance +
                (float) $dailyBalance->total_credit -
                (float) $dailyBalance->total_debit;

            $dailyBalance->save();

            // ----------------------------------------------------------------
            // Upload Multiple Documents - OPTIONAL
            // ----------------------------------------------------------------

            if ($request->hasFile('upload_doc')) {

                $documentDirectory = public_path('upload_doc');

                if (!File::exists($documentDirectory)) {
                    File::makeDirectory(
                        $documentDirectory,
                        0755,
                        true
                    );
                }

                foreach ($request->file('upload_doc') as $document) {

                    $documentName = time() . '_' .
                        Str::random(10) . '.' .
                        $document->getClientOriginalExtension();

                    $document->move(
                        $documentDirectory,
                        $documentName
                    );

                    ExpenseDocument::create([
                        'expense_sn' => $expense->sn,
                        'file_name' => $documentName,
                        'file_path' => 'upload_doc/' . $documentName,
                        'file_type' => $document->getClientMimeType(),
                    ]);
                }
            }

            DB::commit();

            // ----------------------------------------------------------------
            // Reload with documents
            // ----------------------------------------------------------------

            $expense->refresh();
            $expense->load('documents');

            // ----------------------------------------------------------------
            // Response
            // ----------------------------------------------------------------

            return response()->json([
                'success' => true,
                'message' => 'Expense added successfully.',

                'data' => [
                    'id' => $expense->id,
                    'sn' => $expense->sn,
                    'customerid' => $expense->customerid,
                    'add_expenses' => (float) $expense->add_expenses,
                    'total_expenses' => (float) $expense->total_expenses,
                    'expense_category' => $expense->expense_category,

                    'upload_receipt_url' =>
                        $expense->upload_receipt
                            ? asset($expense->upload_receipt)
                            : null,

                    'documents' => $expense->documents->map(
                        function ($document) {
                            return [
                                'id' => $document->id,
                                'file_name' => $document->file_name,
                                'file_type' => $document->file_type,
                                'file_url' => asset($document->file_path),
                            ];
                        }
                    )->values(),

                    'created_date' => $expense->created_date,
                    'updated_date' => $expense->updated_date,
                ],

                'currency' => [
                    'country' =>
                        $balance->currency->country_name ?? null,

                    'currency' =>
                        $balance->currency->currency ?? null,

                    'symbol' =>
                        $balance->currency->symbol ?? null,
                ],

                'daily_balance' => [
                    'date' => $dailyBalance->balance_date,

                    'opening_balance' =>
                        (float) $dailyBalance->opening_balance,

                    'total_credit' =>
                        (float) $dailyBalance->total_credit,

                    'total_debit' =>
                        (float) $dailyBalance->total_debit,

                    'closing_balance' =>
                        (float) $dailyBalance->closing_balance,
                ],
            ], 201);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Expense creation failed', [
                'customerid' => $customerid ?? null,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add expense.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // ========================================================================
    // UPDATE EXPENSE
    // ========================================================================

    public function updateExpense(Request $request, $sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Request is not authorized.',
            ], 401);
        }

        $customerid = $user->customerid;

        // --------------------------------------------------------------------
        // Find expense only for logged-in customer
        // --------------------------------------------------------------------

        $expense = Expense::where('sn', $sn)
            ->where('customerid', $customerid)
            ->first();

        if (!$expense) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found.',
            ], 404);
        }

        $request->validate([
            'add_expenses' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'expense_category' => [
                'nullable',
                'string',
                'max:255',
            ],

            'upload_receipt' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],

            'upload_doc' => [
                'nullable',
                'array',
            ],

            'upload_doc.*' => [
                'file',
                'mimes:jpg,jpeg,png,pdf,doc,docx',
                'max:10240',
            ],
        ]);

        DB::beginTransaction();

        try {

            // ----------------------------------------------------------------
            // Get master balance
            // ----------------------------------------------------------------

            $balance = Balance::where('customerid', $customerid)
                ->with('currency')
                ->first();

            if (!$balance) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Opening balance not found.',
                ], 404);
            }

            // ----------------------------------------------------------------
            // Original and new amount
            // ----------------------------------------------------------------

            $oldAmount = (float) $expense->add_expenses;

            $newAmount = $request->filled('add_expenses')
                ? (float) $request->add_expenses
                : $oldAmount;

            // ----------------------------------------------------------------
            // Transaction date
            // ----------------------------------------------------------------

            $transactionDate = Carbon::parse(
                $expense->created_date
            )->toDateString();

            // ----------------------------------------------------------------
            // Find daily balance
            // ----------------------------------------------------------------

            $dailyBalance = DailyBalance::where(
                    'customerid',
                    $customerid
                )
                ->where(
                    'balance_date',
                    $transactionDate
                )
                ->first();

            if ($dailyBalance) {

                /*
                 * Add the old expense back temporarily.
                 *
                 * Example:
                 * Current balance = 8,000
                 * Old expense = 2,000
                 * Available = 10,000
                 *
                 * Then compare new expense against that amount.
                 */

                $availableBalance =
                    (float) $dailyBalance->closing_balance +
                    $oldAmount;

                if ($newAmount > $availableBalance) {

                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient balance for updated expense.',
                        'available_balance' =>
                            (float) $availableBalance,
                    ], 400);
                }

                // ------------------------------------------------------------
                // Adjust daily debit
                // ------------------------------------------------------------

                $dailyBalance->total_debit =
                    (float) $dailyBalance->total_debit -
                    $oldAmount +
                    $newAmount;

                if ($dailyBalance->total_debit < 0) {
                    $dailyBalance->total_debit = 0;
                }

                // ------------------------------------------------------------
                // Recalculate closing balance
                // ------------------------------------------------------------

                $dailyBalance->closing_balance =
                    (float) $dailyBalance->opening_balance +
                    (float) $dailyBalance->total_credit -
                    (float) $dailyBalance->total_debit;

                $dailyBalance->save();
            }

            // ----------------------------------------------------------------
            // Update Expense Amount
            // ----------------------------------------------------------------

            $expense->add_expenses = $newAmount;

            // ----------------------------------------------------------------
            // Update Category
            // ----------------------------------------------------------------

            if ($request->filled('expense_category')) {
                $expense->expense_category =
                    $request->expense_category;
            }

            // ----------------------------------------------------------------
            // Update Receipt
            // ----------------------------------------------------------------

            if ($request->hasFile('upload_receipt')) {

                // Delete old receipt
                if ($expense->upload_receipt) {

                    $oldReceiptPath = public_path(
                        $expense->upload_receipt
                    );

                    if (File::exists($oldReceiptPath)) {
                        File::delete($oldReceiptPath);
                    }
                }

                // Create folder
                $receiptDirectory =
                    public_path('upload_receipt');

                if (!File::exists($receiptDirectory)) {
                    File::makeDirectory(
                        $receiptDirectory,
                        0755,
                        true
                    );
                }

                // Upload new receipt
                $receipt =
                    $request->file('upload_receipt');

                $receiptName =
                    time() . '_' .
                    Str::random(10) . '.' .
                    $receipt->getClientOriginalExtension();

                $receipt->move(
                    $receiptDirectory,
                    $receiptName
                );

                $expense->upload_receipt =
                    'upload_receipt/' . $receiptName;
            }

            // ----------------------------------------------------------------
            // Recalculate cumulative total expenses
            // ----------------------------------------------------------------

            $totalExpenses = Expense::where(
                    'customerid',
                    $customerid
                )
                ->where(
                    'id',
                    '!=',
                    $expense->id
                )
                ->sum('add_expenses');

            $expense->total_expenses =
                (float) $totalExpenses + $newAmount;

            // ----------------------------------------------------------------
            // Updated date
            // ----------------------------------------------------------------

            $expense->updated_date = now();

            $expense->save();

            // ----------------------------------------------------------------
            // Update Documents ONLY if new documents are provided
            // ----------------------------------------------------------------

            if ($request->hasFile('upload_doc')) {

                // Get old documents
                $oldDocuments = ExpenseDocument::where(
                    'expense_sn',
                    $expense->sn
                )->get();

                // Delete old physical files
                foreach ($oldDocuments as $oldDocument) {

                    $oldFilePath = public_path(
                        $oldDocument->file_path
                    );

                    if (File::exists($oldFilePath)) {
                        File::delete($oldFilePath);
                    }
                }

                // Delete old database records
                ExpenseDocument::where(
                    'expense_sn',
                    $expense->sn
                )->delete();

                // Create document folder
                $documentDirectory =
                    public_path('upload_doc');

                if (!File::exists($documentDirectory)) {
                    File::makeDirectory(
                        $documentDirectory,
                        0755,
                        true
                    );
                }

                // Upload new documents
                foreach (
                    $request->file('upload_doc')
                    as $document
                ) {

                    $documentName =
                        time() . '_' .
                        Str::random(10) . '.' .
                        $document->getClientOriginalExtension();

                    $document->move(
                        $documentDirectory,
                        $documentName
                    );

                    ExpenseDocument::create([
                        'expense_sn' => $expense->sn,
                        'file_name' => $documentName,
                        'file_path' =>
                            'upload_doc/' . $documentName,
                        'file_type' =>
                            $document->getClientMimeType(),
                    ]);
                }
            }

            DB::commit();

            // ----------------------------------------------------------------
            // Reload
            // ----------------------------------------------------------------

            $expense->refresh();

            $expense->load('documents');

            // ----------------------------------------------------------------
            // Response
            // ----------------------------------------------------------------

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully.',

                'data' => [
                    'id' => $expense->id,
                    'sn' => $expense->sn,
                    'customerid' => $expense->customerid,

                    'add_expenses' =>
                        (float) $expense->add_expenses,

                    'total_expenses' =>
                        (float) $expense->total_expenses,

                    'expense_category' =>
                        $expense->expense_category,

                    'upload_receipt_url' =>
                        $expense->upload_receipt
                            ? asset($expense->upload_receipt)
                            : null,

                    'documents' =>
                        $expense->documents->map(
                            function ($document) {
                                return [
                                    'id' => $document->id,
                                    'file_name' =>
                                        $document->file_name,
                                    'file_type' =>
                                        $document->file_type,
                                    'file_url' =>
                                        asset($document->file_path),
                                ];
                            }
                        )->values(),

                    'created_date' =>
                        $expense->created_date,

                    'updated_date' =>
                        $expense->updated_date,
                ],

                'currency' => [
                    'country' =>
                        $balance->currency->country_name ?? null,

                    'currency' =>
                        $balance->currency->currency ?? null,

                    'symbol' =>
                        $balance->currency->symbol ?? null,
                ],

                'daily_balance' => $dailyBalance ? [
                    'date' =>
                        $dailyBalance->balance_date,

                    'opening_balance' =>
                        (float) $dailyBalance->opening_balance,

                    'total_credit' =>
                        (float) $dailyBalance->total_credit,

                    'total_debit' =>
                        (float) $dailyBalance->total_debit,

                    'closing_balance' =>
                        (float) $dailyBalance->closing_balance,
                ] : null,
            ]);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Expense update failed', [
                'customerid' => $customerid,
                'sn' => $sn,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // ========================================================================
    // GET SINGLE EXPENSE
    // ========================================================================

    public function getExpense($sn)
    {
        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request is not authorized.',
                ], 401);
            }

            $customerid = $user->customerid;

            // ----------------------------------------------------------------
            // Find expense for logged-in customer
            // ----------------------------------------------------------------

            $expense = Expense::with('documents')
                ->where('sn', $sn)
                ->where('customerid', $customerid)
                ->first();

            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found.',
                ], 404);
            }

            // ----------------------------------------------------------------
            // Receipt URL
            // ----------------------------------------------------------------

            $receiptUrl = $expense->upload_receipt
                ? asset($expense->upload_receipt)
                : null;

            // ----------------------------------------------------------------
            // Documents
            // ----------------------------------------------------------------

            $documents = $expense->documents->map(
                function ($document) {
                    return [
                        'id' => $document->id,
                        'file_name' => $document->file_name,
                        'file_type' => $document->file_type,
                        'file_url' => asset($document->file_path),
                    ];
                }
            )->values();

            // ----------------------------------------------------------------
            // Currency
            // ----------------------------------------------------------------

            $balance = Balance::with('currency')
                ->where('customerid', $customerid)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Expense retrieved successfully.',

                'data' => [
                    'id' => $expense->id,
                    'sn' => $expense->sn,
                    'customerid' => $expense->customerid,

                    'add_expenses' =>
                        (float) $expense->add_expenses,

                    'total_expenses' =>
                        (float) $expense->total_expenses,

                    'expense_category' =>
                        $expense->expense_category,

                    'upload_receipt_url' =>
                        $receiptUrl,

                    'documents' =>
                        $documents,

                    'created_date' =>
                        $expense->created_date,

                    'updated_date' =>
                        $expense->updated_date,
                ],

                'currency' => [
                    'country' =>
                        $balance->currency->country_name ?? null,

                    'currency' =>
                        $balance->currency->currency ?? null,

                    'symbol' =>
                        $balance->currency->symbol ?? null,
                ],
            ]);

        } catch (\Throwable $e) {

            Log::error('Get expense failed', [
                'sn' => $sn,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // ========================================================================
    // GET EXPENSE LIST
    // ========================================================================

    public function getExpenseList(Request $request)
    {
        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request is not authorized.',
                ], 401);
            }

            $customerid = $user->customerid;

            // ----------------------------------------------------------------
            // Query
            // ----------------------------------------------------------------

            $query = Expense::with('documents')
                ->where('customerid', $customerid);

            // ----------------------------------------------------------------
            // Category filter
            // ----------------------------------------------------------------

            if ($request->filled('expense_category')) {
                $query->where(
                    'expense_category',
                    $request->expense_category
                );
            }

            // ----------------------------------------------------------------
            // Date range
            // ----------------------------------------------------------------

            if (
                $request->filled('start_date') &&
                $request->filled('end_date')
            ) {
                $query->whereBetween('created_date', [
                    $request->start_date,
                    $request->end_date,
                ]);
            }

            // ----------------------------------------------------------------
            // Daily / Weekly / Monthly / Yearly
            // ----------------------------------------------------------------

            if ($request->filled('filter_type')) {

                $filter = $request->filter_type;
                $today = Carbon::now();

                switch ($filter) {

                    case 'daily':

                        $query->whereDate(
                            'created_date',
                            $today->toDateString()
                        );

                        break;

                    case 'weekly':

                        $query->whereBetween(
                            'created_date',
                            [
                                $today->copy()
                                    ->startOfWeek()
                                    ->toDateString(),

                                $today->copy()
                                    ->endOfWeek()
                                    ->toDateString(),
                            ]
                        );

                        break;

                    case 'monthly':

                        $query->whereYear(
                            'created_date',
                            $today->year
                        )->whereMonth(
                            'created_date',
                            $today->month
                        );

                        break;

                    case 'yearly':

                        $query->whereYear(
                            'created_date',
                            $today->year
                        );

                        break;
                }
            }

            // ----------------------------------------------------------------
            // Get expenses
            // ----------------------------------------------------------------

            $expenses = $query
                ->orderBy('created_date', 'ASC')
                ->get();

            // ----------------------------------------------------------------
            // Running total
            // ----------------------------------------------------------------

            $runningTotal = 0;

            foreach ($expenses as $expense) {

                $runningTotal +=
                    (float) $expense->add_expenses;

                $expense->total_expenses =
                    $runningTotal;

                // Receipt URL
                $expense->upload_receipt_url =
                    $expense->upload_receipt
                        ? asset($expense->upload_receipt)
                        : null;

                // Document URLs
                foreach (
                    $expense->documents
                    as $document
                ) {
                    $document->file_url =
                        asset($document->file_path);
                }
            }

            // ----------------------------------------------------------------
            // Currency
            // ----------------------------------------------------------------

            $balance = Balance::with('currency')
                ->where('customerid', $customerid)
                ->first();

            $currency = $balance->currency ?? null;

            // ----------------------------------------------------------------
            // Response
            // ----------------------------------------------------------------

            return response()->json([
                'success' => true,

                'message' =>
                    'Expense list fetched successfully.',

                'filter_type' =>
                    $request->filter_type ?? null,

                'start_date' =>
                    $request->start_date ?? null,

                'end_date' =>
                    $request->end_date ?? null,

                'expense_category' =>
                    $request->expense_category ?? null,

                'currency' =>
                    $currency->currency ?? null,

                'symbol' =>
                    $currency->symbol ?? null,

                'data' => $expenses,
            ]);

        } catch (\Throwable $e) {

            Log::error('Get expense list failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // ========================================================================
    // OLD METHOD NAME - GET EXPENSE LISTS
    // ========================================================================
    // Kept for backward compatibility with your existing frontend/routes.
    // ========================================================================

    public function getExpenseLists(Request $request)
    {
        return $this->getExpenseList($request);
    }


    // ========================================================================
    // DELETE EXPENSE
    // ========================================================================

    public function deleteExpense($sn)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Request is not authorized.',
            ], 401);
        }

        $customerid = $user->customerid;

        // --------------------------------------------------------------------
        // Find expense belonging to logged-in customer
        // --------------------------------------------------------------------

        $expense = Expense::where('sn', $sn)
            ->where('customerid', $customerid)
            ->first();

        if (!$expense) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {

            // ----------------------------------------------------------------
            // Get balance
            // ----------------------------------------------------------------

            $balance = Balance::with('currency')
                ->where('customerid', $customerid)
                ->first();

            if (!$balance) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Opening balance not found.',
                ], 404);
            }

            // ----------------------------------------------------------------
            // Expense amount
            // ----------------------------------------------------------------

            $amount = (float) $expense->add_expenses;

            // ----------------------------------------------------------------
            // Transaction date
            // ----------------------------------------------------------------

            $transactionDate = Carbon::parse(
                $expense->created_date
            )->toDateString();

            // ----------------------------------------------------------------
            // Update daily balance
            // ----------------------------------------------------------------

            $dailyBalance = DailyBalance::where(
                    'customerid',
                    $customerid
                )
                ->where(
                    'balance_date',
                    $transactionDate
                )
                ->first();

            if ($dailyBalance) {

                $dailyBalance->total_debit =
                    (float) $dailyBalance->total_debit -
                    $amount;

                if ($dailyBalance->total_debit < 0) {
                    $dailyBalance->total_debit = 0;
                }

                $dailyBalance->closing_balance =
                    (float) $dailyBalance->opening_balance +
                    (float) $dailyBalance->total_credit -
                    (float) $dailyBalance->total_debit;

                $dailyBalance->save();
            }

            // ----------------------------------------------------------------
            // Delete receipt physical file
            // ----------------------------------------------------------------

            if ($expense->upload_receipt) {

                $receiptPath = public_path(
                    $expense->upload_receipt
                );

                if (File::exists($receiptPath)) {
                    File::delete($receiptPath);
                }
            }

            // ----------------------------------------------------------------
            // Get documents
            // ----------------------------------------------------------------

            $documents = ExpenseDocument::where(
                'expense_sn',
                $expense->sn
            )->get();

            // ----------------------------------------------------------------
            // Delete document physical files
            // ----------------------------------------------------------------

            foreach ($documents as $document) {

                $documentPath = public_path(
                    $document->file_path
                );

                if (File::exists($documentPath)) {
                    File::delete($documentPath);
                }
            }

            // ----------------------------------------------------------------
            // Delete document records
            // ----------------------------------------------------------------

            ExpenseDocument::where(
                'expense_sn',
                $expense->sn
            )->delete();

            // ----------------------------------------------------------------
            // Delete expense
            // ----------------------------------------------------------------

            $expense->delete();

            // ----------------------------------------------------------------
            // Recalculate total expenses
            // ----------------------------------------------------------------

            $newTotalExpenses = (float) Expense::where(
                'customerid',
                $customerid
            )->sum('add_expenses');

            DB::commit();

            // ----------------------------------------------------------------
            // Response
            // ----------------------------------------------------------------

            return response()->json([
                'success' => true,

                'message' =>
                    'Expense deleted successfully.',

                'total_expenses' =>
                    $newTotalExpenses,

                'currency' => [
                    'country' =>
                        $balance->currency->country_name ?? null,

                    'currency' =>
                        $balance->currency->currency ?? null,

                    'symbol' =>
                        $balance->currency->symbol ?? null,
                ],

                'daily_balance' => $dailyBalance ? [
                    'date' =>
                        $dailyBalance->balance_date,

                    'opening_balance' =>
                        (float) $dailyBalance->opening_balance,

                    'total_credit' =>
                        (float) $dailyBalance->total_credit,

                    'total_debit' =>
                        (float) $dailyBalance->total_debit,

                    'closing_balance' =>
                        (float) $dailyBalance->closing_balance,
                ] : null,
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Expense deletion failed', [
                'customerid' => $customerid,
                'sn' => $sn,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // ========================================================================
    // GET TOTAL EXPENSES
    // ========================================================================

    public function getTotalExpenses(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Request is not authorized.',
            ], 401);
        }

        $customerid = $user->customerid;

        $filter = $request->filter ?? 'all';

        $query = Expense::where(
            'customerid',
            $customerid
        );

        $today = Carbon::now();

        switch ($filter) {

            case 'daily':

                $query->whereDate(
                    'created_date',
                    $today->toDateString()
                );

                break;

            case 'weekly':

                $startWeek = $today
                    ->copy()
                    ->startOfWeek()
                    ->toDateString();

                $endWeek = $today
                    ->copy()
                    ->endOfWeek()
                    ->toDateString();

                $query->whereBetween(
                    DB::raw('DATE(created_date)'),
                    [
                        $startWeek,
                        $endWeek
                    ]
                );

                break;

            case 'monthly':

                $query->whereMonth(
                    'created_date',
                    $today->month
                )->whereYear(
                    'created_date',
                    $today->year
                );

                break;

            case 'yearly':

                $query->whereYear(
                    'created_date',
                    $today->year
                );

                break;

            case 'all':
            default:

                // No filter
                break;
        }

        $totalExpenses = (float) $query->sum(
            'add_expenses'
        );

        // --------------------------------------------------------------------
        // Currency
        // --------------------------------------------------------------------

        $balance = Balance::with('currency')
            ->where('customerid', $customerid)
            ->first();

        return response()->json([
            'success' => true,

            'message' =>
                'Total expenses fetched successfully.',

            'filter_type' => $filter,

            'total_expenses' =>
                $totalExpenses,

            'currency' => [
                'country' =>
                    $balance->currency->country_name ?? null,

                'currency' =>
                    $balance->currency->currency ?? null,

                'symbol' =>
                    $balance->currency->symbol ?? null,
            ],
        ]);
    }


    // ========================================================================
    // DAILY EXPENSE GRAPH
    // ========================================================================

    public function getDailyExpenseGraph(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Request is not authorized.',
            ], 401);
        }

        $customerid = $user->customerid;

        // --------------------------------------------------------------------
        // Last 30 days including today
        // --------------------------------------------------------------------

        $endDate = Carbon::today();

        $startDate = Carbon::today()
            ->subDays(29);

        // --------------------------------------------------------------------
        // Get expenses
        // --------------------------------------------------------------------

        $expenses = Expense::where(
                'customerid',
                $customerid
            )
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [
                    $startDate->toDateString(),
                    $endDate->toDateString()
                ]
            )
            ->selectRaw(
                'DATE(created_date) as date,
                 SUM(add_expenses) as total'
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // --------------------------------------------------------------------
        // Build complete 30-day graph
        // --------------------------------------------------------------------

        $graphData = [];

        $dates = [];

        $totals = [];

        for (
            $date = $startDate->copy();
            $date->lte($endDate);
            $date->addDay()
        ) {

            $dateStr = $date->format('Y-m-d');

            $total = isset($expenses[$dateStr])
                ? (float) $expenses[$dateStr]->total
                : 0;

            $graphData[] = [
                'date' => $dateStr,
                'total' => $total,
            ];

            // Backward compatibility
            $dates[] = $dateStr;
            $totals[] = $total;
        }

        // --------------------------------------------------------------------
        // Currency
        // --------------------------------------------------------------------

        $balance = Balance::with('currency')
            ->where('customerid', $customerid)
            ->first();

        return response()->json([
            'success' => true,

            'message' =>
                'Daily expense graph fetched successfully.',

            'start_date' =>
                $startDate->toDateString(),

            'end_date' =>
                $endDate->toDateString(),

            'currency' => [
                'country' =>
                    $balance->currency->country_name ?? null,

                'currency' =>
                    $balance->currency->currency ?? null,

                'symbol' =>
                    $balance->currency->symbol ?? null,
            ],

            // Preferred structure
            'data' => $graphData,

            // Backward compatibility
            'dates' => $dates,

            'totals' => $totals,
        ]);
    }


    // ========================================================================
    // MONTHLY EXPENSE GRAPH
    // ========================================================================

    public function getMonthlyExpenseGraph(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Request is not authorized.',
            ], 401);
        }

        $customerid = $user->customerid;

        // --------------------------------------------------------------------
        // Last 12 months including current month
        // --------------------------------------------------------------------

        $endDate = Carbon::today()
            ->endOfMonth();

        $startDate = Carbon::today()
            ->subMonths(11)
            ->startOfMonth();

        // --------------------------------------------------------------------
        // Get monthly expenses
        // --------------------------------------------------------------------

        $expenses = Expense::where(
                'customerid',
                $customerid
            )
            ->whereBetween(
                DB::raw('DATE(created_date)'),
                [
                    $startDate->toDateString(),
                    $endDate->toDateString()
                ]
            )
            ->selectRaw(
                'YEAR(created_date) as year,
                 MONTH(created_date) as month,
                 SUM(add_expenses) as total'
            )
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        // --------------------------------------------------------------------
        // Build 12-month graph
        // --------------------------------------------------------------------

        $graphData = [];

        $months = [];

        $totals = [];

        for (
            $date = $startDate->copy();
            $date->lte($endDate);
            $date->addMonth()
        ) {

            $year = $date->year;

            $month = $date->month;

            $expenseForMonth = $expenses->first(
                function ($item) use ($year, $month) {

                    return (int) $item->year === $year &&
                        (int) $item->month === $month;
                }
            );

            $label = $date->format('M Y');

            $total = $expenseForMonth
                ? (float) $expenseForMonth->total
                : 0;

            $graphData[] = [
                'month' => $label,
                'year' => $year,
                'total' => $total,
            ];

            // Backward compatibility
            $months[] = $label;

            $totals[] = $total;
        }

        // --------------------------------------------------------------------
        // Currency
        // --------------------------------------------------------------------

        $balance = Balance::with('currency')
            ->where('customerid', $customerid)
            ->first();

        return response()->json([
            'success' => true,

            'message' =>
                'Monthly expense graph fetched successfully.',

            'start_month' =>
                $startDate->format('Y-m'),

            'end_month' =>
                $endDate->format('Y-m'),

            'currency' => [
                'country' =>
                    $balance->currency->country_name ?? null,

                'currency' =>
                    $balance->currency->currency ?? null,

                'symbol' =>
                    $balance->currency->symbol ?? null,
            ],

            // Preferred structure
            'data' => $graphData,

            // Backward compatibility
            'months' => $months,

            'totals' => $totals,
        ]);
    }


    // ========================================================================
    // CATEGORY-WISE EXPENSES
    // ========================================================================

    public function getCategoryWiseExpenses(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Request is not authorized.',
            ], 401);
        }

        $customerid = $user->customerid;

        $query = Expense::where('customerid', $customerid);

        // --------------------------------------------------------------------
        // DATE FILTER
        // --------------------------------------------------------------------

        if ($request->filled('start_date') && $request->filled('end_date')) {

            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate   = Carbon::parse($request->end_date)->endOfDay();

            $query->whereBetween('created_date', [
                $startDate,
                $endDate
            ]);
        }

        // --------------------------------------------------------------------
        // DAILY / WEEKLY / MONTHLY / YEARLY FILTER
        // --------------------------------------------------------------------

        if ($request->filled('filter_type')) {

            $today = Carbon::now();

            switch (strtolower($request->filter_type)) {

                // ------------------------------------------------------------
                // DAILY
                // ------------------------------------------------------------
                case 'daily':

                    $startOfDay = $today->copy()->startOfDay();
                    $endOfDay   = $today->copy()->endOfDay();

                    $query->whereBetween('created_date', [
                        $startOfDay,
                        $endOfDay
                    ]);

                    break;

                // ------------------------------------------------------------
                // WEEKLY
                // ------------------------------------------------------------
                case 'weekly':

                    $startOfWeek = $today->copy()->startOfWeek();
                    $endOfWeek   = $today->copy()->endOfWeek();

                    $query->whereBetween('created_date', [
                        $startOfWeek,
                        $endOfWeek
                    ]);

                    break;

                // ------------------------------------------------------------
                // MONTHLY
                // ------------------------------------------------------------
                case 'monthly':

                    $startOfMonth = $today->copy()->startOfMonth();
                    $endOfMonth   = $today->copy()->endOfMonth();

                    $query->whereBetween('created_date', [
                        $startOfMonth,
                        $endOfMonth
                    ]);

                    break;

                // ------------------------------------------------------------
                // YEARLY
                // ------------------------------------------------------------
                case 'yearly':

                    $startOfYear = $today->copy()->startOfYear();
                    $endOfYear   = $today->copy()->endOfYear();

                    $query->whereBetween('created_date', [
                        $startOfYear,
                        $endOfYear
                    ]);

                    break;
            }
        }

        // --------------------------------------------------------------------
        // GROUP BY CATEGORY
        // --------------------------------------------------------------------

        $categoryExpenses = $query
            ->select(
                'expense_category',
                DB::raw('SUM(add_expenses) as total_amount')
            )
            ->groupBy('expense_category')
            ->orderBy('expense_category')
            ->get();

        // --------------------------------------------------------------------
        // CURRENCY
        // --------------------------------------------------------------------

        $balance = Balance::with('currency')
            ->where('customerid', $customerid)
            ->first();

        $currency = $balance->currency ?? null;

        // --------------------------------------------------------------------
        // RESPONSE
        // --------------------------------------------------------------------

        return response()->json([
            'success' => true,

            'message' =>
                'Category-wise expenses fetched successfully.',

            'currency' =>
                $currency->currency ?? null,

            'symbol' =>
                $currency->symbol ?? null,

            'data' =>
                $categoryExpenses,
        ]);
    }
}