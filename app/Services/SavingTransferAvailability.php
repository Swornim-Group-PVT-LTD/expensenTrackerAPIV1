<?php

namespace App\Services;

use App\Models\Saving;
use Illuminate\Support\Collection;

class SavingTransferAvailability
{
    public function remainingCents(Collection $entries): array
    {
        $linked = [];
        $unlinked = [];

        foreach ($entries->whereNotNull('transfer_key') as $transfer) {
            $cents = (int) round($transfer->add_saving * 100);

            if ($transfer->source_saving_id !== null) {
                $id = $transfer->source_saving_id;
                $linked[$id] = ($linked[$id] ?? 0) + $cents;
            } else {
                $category = mb_strtolower(trim($transfer->saving_category));
                $unlinked[$category] = ($unlinked[$category] ?? 0) + $cents;
            }
        }

        // Category transfers have no source row. Apply them to the oldest remaining entries.
        $remaining = [];
        foreach ($entries->whereNull('transfer_key')->sortBy('id') as $saving) {
            $category = mb_strtolower(trim($saving->saving_category));
            $cents = max(0, (int) round($saving->add_saving * 100) - ($linked[$saving->id] ?? 0));
            $deduction = min($cents, $unlinked[$category] ?? 0);
            $unlinked[$category] = ($unlinked[$category] ?? 0) - $deduction;
            $remaining[$saving->id] = $cents - $deduction;
        }

        return $remaining;
    }
}
