<?php

namespace App\Console\Commands;

use App\Jobs\ConfirmMpesaPayment;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Ask Daraja about payments still pending after five minutes, and send the ones nobody can settle to review';

    public function handle(): int
    {
        $stuck = Payment::where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(5))
            ->orderByRaw('CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('checked_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $checked = 0;
        $review = [];
        foreach ($stuck as $payment) {
            $reason = match (true) {
                $payment->created_at <= now()->subDay() => 'No final answer after 24 hours: check manually',
                $payment->checkout_request_id === null => 'No CheckoutRequestID: the prompt may or may not have been sent, check manually',
                default => null,
            };

            if ($reason === null) {
                Payment::whereKey($payment->id)->update(['checked_at' => now()]);
                ConfirmMpesaPayment::dispatch($payment->id);
                $checked++;

                continue;
            }

            if (Payment::whereKey($payment->id)->where('status', 'pending')->update(['status' => 'needs_review', 'result_desc' => $reason]) === 1) {
                $review[] = $payment->id;
                Log::error('M-Pesa payment needs review', ['payment' => $payment->id, 'reason' => $reason]);
            }
        }

        $this->info("Sent {$checked} payment(s) to the status query.");
        if ($review !== []) {
            $this->warn('Needs review: payment '.implode(', ', $review));
        }

        return self::SUCCESS;
    }
}
