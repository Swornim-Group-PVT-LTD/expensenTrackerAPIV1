<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\SavingController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\IncomeCategoryController;
use App\Http\Controllers\SavingCategoryController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\InvestmentCategoryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseThresholdController;
use App\Http\Controllers\InvoiceController;
/*
|--------------------------------------------------------------------------
| API RoutesSS
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth.token:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes example
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    // Other protected routes here
});

//Balancce Routes
Route::middleware('auth.token:sanctum')->prefix('balances')->group(function () {
Route::post('/', [BalanceController::class, 'addBalance']); // POST /api/balances
Route::get('/', [BalanceController::class, 'getBalance']); // GET /api/balances
Route::get('/monthly-remaining', [BalanceController::class, 'getMonthlyRemainingBalanceGraph']);
Route::get('/account-statement', [BalanceController::class, 'getAccountStatement']);
Route::get('/summary', [BalanceController::class, 'getDailyBalance']);
});

//Income Routes
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/incomes', [IncomeController::class, 'addIncome']);  
    Route::get('/incomes', [IncomeController::class, 'getIncomeList']);  
    Route::get('/income/total', [IncomeController::class, 'getTotalIncome']);
    Route::put('/incomes/update/{sn}', [IncomeController::class, 'updateIncome']);
    Route::delete('/incomes/delete/{sn}', [IncomeController::class, 'deleteIncome']);
    Route::get('/incomes/graph/daily', [IncomeController::class, 'getDailyIncomeGraph']);
    Route::get('/incomes/graph/monthly', [IncomeController::class, 'getMonthlyIncomeGraph']);

});

//Saving Routes
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/savings', [SavingController::class, 'addSaving']);  // Add saving
    Route::get('/savings', [SavingController::class, 'getSavingLists']);   // Get saving list
    Route::get('/saving/total', [SavingController::class, 'getTotalSaving']);
    Route::put('/savings/update/{sn}', [SavingController::class, 'updateSaving']);
    Route::delete('/savings/delete/{sn}', [SavingController::class, 'deleteSaving']);
    Route::get('/savings/graph/daily', [SavingController::class, 'getDailySavingGraph']);
    Route::get('/savings/graph/monthly', [SavingController::class, 'getMonthlySavingGraph']);
});

//Expenses Routes
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/expenses', [ExpenseController::class, 'addExpense']);
    Route::get('/expenses', [ExpenseController::class, 'getExpenseLists']);
    Route::get('/expenses/total', [ExpenseController::class, 'getTotalExpenses']);
    Route::put('/expenses/update/{sn}', [ExpenseController::class, 'updateExpense']);
    Route::delete('/expenses/delete/{sn}', [ExpenseController::class, 'deleteExpense']);
    Route::get('/expenses/graph/daily', [ExpenseController::class, 'getDailyExpenseGraph']);
    Route::get('/expenses/graph/monthly', [ExpenseController::class, 'getMonthlyExpenseGraph']);
    Route::get('/expenses/category-wise', [ExpenseController::class, 'getCategoryWiseExpenses']);
    Route::get('/expenses/{sn}/receipt', [ExpenseController::class, 'downloadReceipt'])->name('expenses.receipt');
    Route::get('/expenses/{sn}/documents/{documentId}', [ExpenseController::class, 'downloadDocument'])->whereNumber('documentId')->name('expenses.document');
});

//Investment Routes
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/investments', [InvestmentController::class, 'addInvestment']);
    Route::get('/investments', [InvestmentController::class, 'getInvestmentList']);
    Route::get('/investment/total', [InvestmentController::class, 'getTotalInvestment']);
    Route::put('/investments/update/{sn}', [InvestmentController::class, 'updateInvestment']);
    Route::delete('/investments/delete/{sn}', [InvestmentController::class, 'deleteInvestment']);
    Route::get('/investments/graph/daily', [InvestmentController::class, 'getDailyInvestmentGraph']);
     Route::get('/investments/graph/monthly', [InvestmentController::class, 'getMonthlyInvestmentGraph']);

});

//Income Category
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/incomeCategory/add', [IncomeCategoryController::class, 'addIncomeCategory']);
    Route::get('/incomeCategory/list', [IncomeCategoryController::class, 'getIncomeCategoryLists']);
    Route::get('/incomeCategory/{id}', [IncomeCategoryController::class, 'getOneIncomeCateory']);       // Get by ID
    Route::put('/incomeCategory/{id}', [IncomeCategoryController::class, 'updateIncomeCategory']);    // Update by ID
    Route::delete('/incomeCategory/{id}', [IncomeCategoryController::class, 'destroyIncomeCategory']);
});

//Saving Category
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/savingCategory/add', [SavingCategoryController::class, 'addSavingCategory']);
    Route::get('/savingCategory/list', [SavingCategoryController::class, 'getSavingCategoryList']);
    Route::get('/savingCategory/{id}', [SavingCategoryController::class, 'getSavingCategoryBySN']);
    Route::put('/savingCategory/{id}', [SavingCategoryController::class, 'updateSavingCategory']);
    Route::delete('/savingCategory/{id}', [SavingCategoryController::class, 'destroySavingCategory']);
});

//Expenses Category
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/expenseCategory/add', [ExpenseCategoryController::class, 'addExpenseCategory']);
    Route::get('/expenseCategory/list', [ExpenseCategoryController::class, 'getExpenseCategory']);
    Route::get('/expenseCategory/{id}', [ExpenseCategoryController::class, 'getExpenseCategoryById']);
    Route::put('/expenseCategory/{id}', [ExpenseCategoryController::class, 'updateExpenseCategory']);
    Route::delete('/expenseCategory/{id}', [ExpenseCategoryController::class, 'deleteExpenseCategory']);
});

//Investment Category
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/investmentCategory/add', [InvestmentCategoryController::class, 'addInvestmentCategory']);
    Route::get('/investmentCategory/list', [InvestmentCategoryController::class, 'getInvestmentCategory']);
    Route::get('/investmentCategory/{id}', [InvestmentCategoryController::class, 'getInvestmentCategoryById']);
    Route::put('/investmentCategory/{id}', [InvestmentCategoryController::class, 'updateInvestmentCategory']);
    Route::delete('/investmentCategory/{id}', [InvestmentCategoryController::class, 'deleteInvestmentCategory']);
});

// Currency Category
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/currency/add', [CurrencyController::class, 'addCurrency']);
    Route::get('/currency/all', [CurrencyController::class, 'getCurrencies']);
    Route::put('/currency/update/{sn}', [CurrencyController::class, 'updateCurrency']);
    Route::delete('/currency/delete/{sn}', [CurrencyController::class, 'deleteCurrency']);
});

// Dashboard Route
Route::middleware('auth.token:sanctum')->group(function () {
    Route::get('/dashboard-graph', [DashboardController::class, 'getDashboardData']);
});

Route::middleware('auth.token:sanctum')->group(function () {
    Route::get('/expense-thresholds', [ExpenseThresholdController::class, 'getAllThresholds']);
    Route::post('/expense-thresholds', [ExpenseThresholdController::class, 'addExpenseThreshold']);
    
    // Use SN for single threshold operations
    Route::get('/expense-thresholds/{sn}', [ExpenseThresholdController::class, 'getExpenseThreshold']);
    Route::put('/expense-thresholds/{sn}', [ExpenseThresholdController::class, 'updateExpenseThreshold']);
    Route::delete('/expense-thresholds/{sn}', [ExpenseThresholdController::class, 'deleteExpenseThreshold']);
    Route::get('/expense-threshold/compare', [ExpenseThresholdController::class, 'compareThreshold']);

});

// Invoice Routes
Route::middleware('auth.token:sanctum')->group(function () {
    Route::post('/invoices', [InvoiceController::class, 'addInvoice']);
    Route::get('/invoices', [InvoiceController::class, 'getInvoiceList']);
    Route::get('/invoices/{sn}', [InvoiceController::class, 'getInvoice']);
    Route::put('/invoices/{sn}', [InvoiceController::class, 'updateInvoice']);
    Route::patch('/invoices/{sn}/status', [InvoiceController::class, 'updateInvoiceStatus']);
    Route::delete('/invoices/{sn}', [InvoiceController::class, 'deleteInvoice']);
});