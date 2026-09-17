<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | ADD EXPENSE
    |--------------------------------------------------------------------------
    */

    public function addExpense(Request $request)
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | Get authenticated user
            |--------------------------------------------------------------------------
            */
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            $customerid = $user->customerid;

            /*
            |--------------------------------------------------------------------------
            | Validation
            |--------------------------------------------------------------------------
            */
            $validated = $request->validate([
                'add_expenses' => 'required|numeric|min:0',

                'expense_category' => 'required|string|max:255',

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

            /*
            |--------------------------------------------------------------------------
            | Generate unique Expense SN
            |--------------------------------------------------------------------------
            */
            do {
                $sn = 'EXP' . strtoupper(Str::random(8));
            } while (Expense::where('sn', $sn)->exists());

            /*
            |--------------------------------------------------------------------------
            | Create Expense
            |--------------------------------------------------------------------------
            */
            $expense = Expense::create([
                'sn' => $sn,
                'customerid' => $customerid,
                'add_expenses' => $validated['add_expenses'],
                'total_expenses' => 0,
                'expense_category' => $validated['expense_category'],
                'upload_receipt' => null,
                'created_date' => now(),
                'updated_date' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Upload Receipt
            |--------------------------------------------------------------------------
            */
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

                $receiptName = time() . '_' . Str::random(10) . '.' .
                    $receipt->getClientOriginalExtension();

                $receipt->move(
                    $receiptDirectory,
                    $receiptName
                );

                $expense->update([
                    'upload_receipt' => 'upload_receipt/' . $receiptName,
                    'updated_date' => now(),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate Total Expenses
            |--------------------------------------------------------------------------
            */
            $totalExpenses = Expense::where(
                'customerid',
                $customerid
            )->sum('add_expenses');

            $expense->update([
                'total_expenses' => $totalExpenses,
                'updated_date' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Upload Multiple Documents
            |--------------------------------------------------------------------------
            */
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

            /*
            |--------------------------------------------------------------------------
            | Reload Expense
            |--------------------------------------------------------------------------
            */
            $expense->refresh();

            $expense->load('documents');

            /*
            |--------------------------------------------------------------------------
            | Success Response
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'success' => true,
                'message' => 'Expense added successfully.',

                'data' => [
                    'sn' => $expense->sn,

                    'customerid' => $expense->customerid,

                    'add_expenses' => $expense->add_expenses,

                    'total_expenses' => $expense->total_expenses,

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
                                'file_url' => asset(
                                    $document->file_path
                                ),
                            ];
                        }
                    ),

                    'created_date' => $expense->created_date,
                    'updated_date' => $expense->updated_date,
                ],
            ], 201);

        } catch (ValidationException $e) {

            /*
            |--------------------------------------------------------------------------
            | Validation Error
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Log Error
            |--------------------------------------------------------------------------
            */
            Log::error('Expense creation failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Error Response
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'success' => false,
                'message' => 'Failed to add expense.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /*
    |--------------------------------------------------------------------------
    | UPDATE EXPENSE
    |--------------------------------------------------------------------------
    */

    public function updateExpense(Request $request,$sn) 
    {

        $expense =
            Expense::where('sn', $sn)->first();

        if (!$expense) {

            return response()->json([
                'message' =>
                    'Expense not found.',
            ], 404);
        }

        $request->validate([

            'add_expenses' =>
                'nullable|numeric',

            'total_expenses' =>
                'nullable|numeric',

            'expense_category' =>
                'nullable',

            'upload_receipt' =>
                'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',

            'upload_doc' =>
                'nullable|array',

            'upload_doc.*' =>
                'file|mimes:jpg,jpeg,png,pdf,doc,docx|max:10240',
        ]);

        try {

            /*
            |--------------------------------------------------------------------------
            | Update Expense Information
            |--------------------------------------------------------------------------
            */

            if ($request->has('add_expenses')) {

                $expense->add_expenses =
                    $request->add_expenses;
            }

            if ($request->has('total_expenses')) {

                $expense->total_expenses =
                    $request->total_expenses;
            }

            if ($request->has('expense_category')) {

                $expense->expense_category =
                    $request->expense_category;
            }

            /*
            |--------------------------------------------------------------------------
            | Update Receipt
            |--------------------------------------------------------------------------
            */

            if ($request->hasFile('upload_receipt')) {

                /*
                |--------------------------------------------------------------------------
                | Delete Old Receipt
                |--------------------------------------------------------------------------
                */

                if ($expense->upload_receipt) {

                    $oldReceipt =
                        public_path(
                            'upload_receipt/' .
                            $expense->upload_receipt
                        );

                    if (File::exists($oldReceipt)) {

                        File::delete(
                            $oldReceipt
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | New Receipt
                |--------------------------------------------------------------------------
                */

                $receipt =
                    $request->file(
                        'upload_receipt'
                    );

                $receiptFolder =
                    public_path(
                        'upload_receipt'
                    );

                if (!File::exists($receiptFolder)) {

                    File::makeDirectory(
                        $receiptFolder,
                        0755,
                        true
                    );
                }

                $receiptName =
                    time() . '_' .
                    uniqid() . '.' .
                    $receipt->getClientOriginalExtension();

                $receipt->move(
                    $receiptFolder,
                    $receiptName
                );

                $expense->upload_receipt =
                    $receiptName;
            }

            /*
            |--------------------------------------------------------------------------
            | Update Multiple Documents
            |--------------------------------------------------------------------------
            */

            if ($request->hasFile('upload_doc')) {

                /*
                |--------------------------------------------------------------------------
                | Get Old Documents
                |--------------------------------------------------------------------------
                */

                $oldDocuments =
                    ExpenseDocument::where(
                        'expense_sn',
                        $expense->sn
                    )->get();

                /*
                |--------------------------------------------------------------------------
                | Delete Old Files
                |--------------------------------------------------------------------------
                */

                foreach (
                    $oldDocuments
                    as $oldDocument
                ) {

                    $oldFile =
                        public_path(
                            $oldDocument->file_path
                        );

                    if (File::exists($oldFile)) {

                        File::delete(
                            $oldFile
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Delete Old Database Records
                |--------------------------------------------------------------------------
                */

                ExpenseDocument::where(
                    'expense_sn',
                    $expense->sn
                )->delete();

                /*
                |--------------------------------------------------------------------------
                | Upload New Documents
                |--------------------------------------------------------------------------
                */

                $documentFolder =
                    public_path(
                        'upload_doc'
                    );

                if (!File::exists($documentFolder)) {

                    File::makeDirectory(
                        $documentFolder,
                        0755,
                        true
                    );
                }

                foreach (
                    $request->file('upload_doc')
                    as $document
                ) {

                    $documentName =
                        time() . '_' .
                        uniqid() . '.' .
                        $document->getClientOriginalExtension();

                    /*
                    |--------------------------------------------------------------------------
                    | Move File
                    |--------------------------------------------------------------------------
                    */

                    $document->move(
                        $documentFolder,
                        $documentName
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Create Database Record
                    |--------------------------------------------------------------------------
                    */

                    ExpenseDocument::create([
                        'expense_sn' =>
                            $expense->sn,

                        'file_name' =>
                            $documentName,

                        'file_path' =>
                            'upload_doc/' .
                            $documentName,

                        'file_type' =>
                            $document
                                ->getClientOriginalExtension(),
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Updated Date
            |--------------------------------------------------------------------------
            */

            $expense->updated_date =
                now();

            $expense->save();

            /*
            |--------------------------------------------------------------------------
            | Load Documents
            |--------------------------------------------------------------------------
            */

            $expense->load('documents');

            /*
            |--------------------------------------------------------------------------
            | URLs
            |--------------------------------------------------------------------------
            */

            $expense->upload_receipt_url =
                $expense->upload_receipt
                    ? url(
                        'upload_receipt/' .
                        $expense->upload_receipt
                    )
                    : null;

            foreach (
                $expense->documents
                as $document
            ) {

                $document->file_url =
                    url($document->file_path);
            }

            return response()->json([
                'message' =>
                    'Expense updated successfully.',

                'data' =>
                    $expense,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'message' =>
                    'Failed to update expense.',

                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET SINGLE EXPENSE
    |--------------------------------------------------------------------------
    */

    public function getExpense($sn)
    {
        $expense =
            Expense::with('documents')
                ->where('sn', $sn)
                ->first();

        if (!$expense) {

            return response()->json([
                'message' =>
                    'Expense not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Receipt URL
        |--------------------------------------------------------------------------
        */

        $expense->upload_receipt_url =
            $expense->upload_receipt
                ? url(
                    'upload_receipt/' .
                    $expense->upload_receipt
                )
                : null;

        /*
        |--------------------------------------------------------------------------
        | Document URLs
        |--------------------------------------------------------------------------
        */

        foreach (
            $expense->documents
            as $document
        ) {

            $document->file_url =
                url($document->file_path);
        }

        return response()->json([
            'message' =>
                'Expense retrieved successfully.',

            'data' =>
                $expense,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | GET EXPENSE LIST
    |--------------------------------------------------------------------------
    */

    public function getExpenseList( Request $request ) 
    {

        $query =
            Expense::with('documents');

        /*
        |--------------------------------------------------------------------------
        | Customer Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('customerid')) {

            $query->where(
                'customerid',
                $request->customerid
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get Expenses
        |--------------------------------------------------------------------------
        */

        $expenses =
            $query
                ->orderBy(
                    'created_date',
                    'desc'
                )
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Add URLs
        |--------------------------------------------------------------------------
        */

        foreach (
            $expenses
            as $expense
        ) {

            /*
            | Receipt
            */

            $expense->upload_receipt_url =
                $expense->upload_receipt
                    ? url(
                        'upload_receipt/' .
                        $expense->upload_receipt
                    )
                    : null;

            /*
            | Documents
            */

            foreach (
                $expense->documents
                as $document
            ) {

                $document->file_url =
                    url(
                        $document->file_path
                    );
            }
        }

        return response()->json([
            'message' =>
                'Expense list retrieved successfully.',

            'data' =>
                $expenses,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE EXPENSE
    |--------------------------------------------------------------------------
    */

    public function deleteExpense($sn)
    {
        $expense =
            Expense::where('sn', $sn)->first();

        if (!$expense) {

            return response()->json([
                'message' =>
                    'Expense not found.',
            ], 404);
        }

        try {

            /*
            |--------------------------------------------------------------------------
            | Delete Receipt
            |--------------------------------------------------------------------------
            */

            if ($expense->upload_receipt) {

                $receiptPath =
                    public_path(
                        'upload_receipt/' .
                        $expense->upload_receipt
                    );

                if (File::exists($receiptPath)) {

                    File::delete(
                        $receiptPath
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Get Documents
            |--------------------------------------------------------------------------
            */

            $documents =
                ExpenseDocument::where(
                    'expense_sn',
                    $expense->sn
                )->get();

            /*
            |--------------------------------------------------------------------------
            | Delete Document Files
            |--------------------------------------------------------------------------
            */

            foreach (
                $documents
                as $document
            ) {

                $documentPath =
                    public_path(
                        $document->file_path
                    );

                if (File::exists($documentPath)) {

                    File::delete(
                        $documentPath
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Delete Document Records
            |--------------------------------------------------------------------------
            */

            ExpenseDocument::where(
                'expense_sn',
                $expense->sn
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Delete Expense
            |--------------------------------------------------------------------------
            */

            $expense->delete();

            return response()->json([
                'message' =>
                    'Expense deleted successfully.',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'message' =>
                    'Failed to delete expense.',

                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }
}