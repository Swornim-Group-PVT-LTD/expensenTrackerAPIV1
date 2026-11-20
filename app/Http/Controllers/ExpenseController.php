<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Str;

use App\Models\Expense;
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

        $newFiles = [];
        $committed = false;
        DB::beginTransaction();

        try {

            // ----------------------------------------------------------------
            // Get master balance
            // ----------------------------------------------------------------

            $balance = Balance::where('customerid', $customerid)
                ->with('currency')
                ->lockForUpdate()
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
                $expense->upload_receipt = $this->storeAttachment(
                    $request->file('upload_receipt'), 'upload_receipt', $newFiles
                );
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

            $this->storeDocuments($expense, $request->file('upload_doc') ?? [], $newFiles);

            DB::commit();
            $committed = true;

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
                            ? route('expenses.receipt', ['sn' => $expense->sn])
                            : null,

                    'documents' => $expense->documents->map(
                        function ($document) use ($expense) {
                            return [
                                'id' => $document->id,
                                'file_name' => $document->file_name,
                                'file_type' => $document->file_type,
                                'file_url' => route('expenses.document', ['sn' => $expense->sn, 'documentId' => $document->id]),
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
        } finally {
            if (!$committed) {
                $this->deleteAttachments($newFiles);
            }
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

        $newFiles = [];
        $oldFiles = [];
        $committed = false;
        DB::beginTransaction();

        try {

            // ----------------------------------------------------------------
            // Get master balance
            // ----------------------------------------------------------------

            $balance = Balance::where('customerid', $customerid)
                ->with('currency')
                ->lockForUpdate()
                ->first();

            if (!$balance) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Opening balance not found.',
                ], 404);
            }

            $expense = Expense::where('sn', $sn)
                ->where('customerid', $customerid)
                ->lockForUpdate()
                ->first();

            if (!$expense) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found.',
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
                $oldFiles[] = $expense->upload_receipt;
                $expense->upload_receipt = $this->storeAttachment(
                    $request->file('upload_receipt'), 'upload_receipt', $newFiles
                );
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
                $oldFiles = array_merge($oldFiles, $expense->documents()->pluck('file_path')->all());
                $expense->documents()->delete();
                $this->storeDocuments($expense, $request->file('upload_doc'), $newFiles);
            }

            DB::commit();
            $committed = true;

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
                            ? route('expenses.receipt', ['sn' => $expense->sn])
                            : null,

                    'documents' =>
                        $expense->documents->map(
                            function ($document) use ($expense) {
                                return [
                                    'id' => $document->id,
                                    'file_name' =>
                                        $document->file_name,
                                    'file_type' =>
                                        $document->file_type,
                                    'file_url' =>
                                        route('expenses.document', ['sn' => $expense->sn, 'documentId' => $document->id]),
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
        } finally {
            $this->deleteAttachments($committed ? $oldFiles : $newFiles);
        }
    }


    public function downloadReceipt(string $sn)
    {
        $expense = $this->findOwnedExpense($sn);

        return $this->downloadAttachment($expense->upload_receipt);
    }

    public function downloadDocument(string $sn, string $documentId)
    {
        $document = $this->findOwnedExpense($sn)->documents()->whereKey($documentId)->firstOrFail();

        return $this->downloadAttachment($document->file_path);
    }

    private function findOwnedExpense(string $sn): Expense
    {
        return Expense::where('sn', $sn)
            ->where('customerid', Auth::user()->customerid)
            ->firstOrFail();
    }

    private function downloadAttachment(?string $path)
    {
        $disk = Storage::disk('expense_attachments');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->download($path, basename($path), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function storeAttachment(UploadedFile $file, string $directory, array &$newFiles): string
    {
        $path = $file->store($directory, 'expense_attachments');
        if (!$path) {
            throw new \RuntimeException('Unable to store expense attachment.');
        }

        $newFiles[] = $path;

        return $path;
    }

    private function storeDocuments(Expense $expense, array $documents, array &$newFiles): void
    {
        foreach ($documents as $document) {
            $type = $document->getMimeType();
            $path = $this->storeAttachment($document, 'upload_doc', $newFiles);

            $expense->documents()->create([
                'file_name' => basename($path),
                'file_path' => $path,
                'file_type' => $type,
            ]);
        }
    }

    private function deleteAttachments(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            try {
                Storage::disk('expense_attachments')->delete($path);
            } catch (\Throwable $exception) {
                report($exception);
            }
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
                ? route('expenses.receipt', ['sn' => $expense->sn])
                : null;

            // ----------------------------------------------------------------
            // Documents
            // ----------------------------------------------------------------

            $documents = $expense->documents->map(
                function ($document) use ($expense) {
                    return [
                        'id' => $document->id,
                        'file_name' => $document->file_name,
                        'file_type' => $document->file_type,
                        'file_url' => route('expenses.document', ['sn' => $expense->sn, 'documentId' => $document->id]),
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
                        ? route('expenses.receipt', ['sn' => $expense->sn])
                        : null;

                // Document URLs
                foreach (
                    $expense->documents
                    as $document
                ) {
                    $document->file_url =
                        route('expenses.document', ['sn' => $expense->sn, 'documentId' => $document->id]);
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

        $oldFiles = [];
        $committed = false;
        DB::beginTransaction();

        try {

            // ----------------------------------------------------------------
            // Get balance
            // ----------------------------------------------------------------

            $balance = Balance::with('currency')
                ->where('customerid', $customerid)
                ->lockForUpdate()
                ->first();

            if (!$balance) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Opening balance not found.',
                ], 404);
            }

            $expense = Expense::where('sn', $sn)
                ->where('customerid', $customerid)
                ->lockForUpdate()
                ->first();

            if (!$expense) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found.',
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
            // Remove attachment records before deleting their files after commit.
            // ----------------------------------------------------------------

            $oldFiles = $expense->documents()->pluck('file_path')->all();
            $oldFiles[] = $expense->upload_receipt;
            $expense->documents()->delete();

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
            $committed = true;

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
        } finally {
            if ($committed) {
                $this->deleteAttachments($oldFiles);
            }
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

        $query = Expense::where(
            'customerid',
            $customerid
        );

        // --------------------------------------------------------------------
        // Date range filter
        // --------------------------------------------------------------------

        if (
            $request->filled('start_date') &&
            $request->filled('end_date')
        ) {

            $query->whereBetween(
                'created_date',
                [
                    $request->start_date,
                    $request->end_date
                ]
            );
        }

        // --------------------------------------------------------------------
        // Daily / Weekly / Monthly / Yearly
        // --------------------------------------------------------------------

        if ($request->filled('filter_type')) {

            $today = Carbon::now();

            switch ($request->filter_type) {

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
                                ->startOfWeek(),

                            $today->copy()
                                ->endOfWeek(),
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
            }
        }

        // --------------------------------------------------------------------
        // Group by category
        // --------------------------------------------------------------------

        $categoryExpenses = $query
            ->select(
                'expense_category',
                DB::raw(
                    'SUM(add_expenses) as total_amount'
                )
            )
            ->groupBy('expense_category')
            ->orderBy('expense_category')
            ->get();

        // --------------------------------------------------------------------
        // Currency
        // --------------------------------------------------------------------

        $balance = Balance::with('currency')
            ->where('customerid', $customerid)
            ->first();

        $currency = $balance->currency ?? null;

        // --------------------------------------------------------------------
        // Response
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