<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\DailyBalance;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BalanceService
{
    /**
     * Get today's balance row or create it from previous closing balance.
     */
    public function getOrCreateTodayBalance(string $customerId): DailyBalance
    {
        $today = Carbon::today()->toDateString();

        return DB::transaction(function () use ($customerId, $today) {

            $existing = DailyBalance::where('customerid', $customerId)
                ->where('balance_date', $today)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $previous = DailyBalance::where('customerid', $customerId)
                ->where('balance_date', '<', $today)
                ->orderByDesc('balance_date')
                ->lockForUpdate()
                ->first();

            $master = Balance::where('customerid', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            $opening = $previous
                ? $previous->closing_balance
                : $master->opening_balance;

            return DailyBalance::create([
                'customerid'      => $customerId,
                'balance_date'    => $today,
                'opening_balance' => $opening,
                'total_credit'    => 0,
                'total_debit'     => 0,
                'closing_balance' => $opening,
                'currency_id'     => $master->currency_id,
            ]);
        });
    }

    /**
     * Add income (credit).
     */
    public function addCredit(string $customerId, float $amount): DailyBalance
    {
        return DB::transaction(function () use ($customerId, $amount) {

            $daily = $this->getOrCreateTodayBalance($customerId);

            $daily->refresh();

            $daily->total_credit += $amount;
            $daily->closing_balance = $daily->opening_balance
                + $daily->total_credit
                - $daily->total_debit;

            $daily->save();

            return $daily;
        });
    }

    /**
     * Add expense/saving/investment (debit).
     */
    public function addDebit(string $customerId, float $amount): DailyBalance
    {
        return DB::transaction(function () use ($customerId, $amount) {

            $daily = $this->getOrCreateTodayBalance($customerId);

            $daily->refresh();

            $daily->total_debit += $amount;
            $daily->closing_balance = $daily->opening_balance
                + $daily->total_credit
                - $daily->total_debit;

            $daily->save();

            return $daily;
        });
    }
}