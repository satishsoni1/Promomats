<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Notifications\DocumentActionNotification;
use Illuminate\Console\Command;

class FlagDocumentLifecycle extends Command
{
    protected $signature = 'documents:flag-lifecycle';
    protected $description = 'Flags documents as aging or expired based on their expiry_date, and notifies owners.';

    public function handle(): int
    {
        $documents = Document::whereNotNull('expiry_date')
            ->whereNotIn('status', ['archived', 'rejected'])
            ->get();

        $expiredCount = 0;
        $agingCount = 0;

        foreach ($documents as $document) {
            $wasExpired = $document->is_expired;
            $wasAging = $document->is_aging_flagged;

            $nowExpired = $document->computeIsExpired();
            $nowAging = $document->computeIsAging();
            $isSettled = in_array($document->status, ['approved', 'approved_for_production', 'approved_for_distribution', 'pending_expiration']);

            $status = $document->status;
            if ($nowExpired) {
                $status = 'expired';
            } elseif ($nowAging && $isSettled) {
                $status = 'pending_expiration'; // distinct from the boolean flag - a visible lifecycle state once approved material nears expiry
            }

            $document->forceFill([
                'is_expired' => $nowExpired,
                'is_aging_flagged' => $nowAging,
                'status' => $status,
            ])->save();

            if ($nowExpired && ! $wasExpired) {
                $expiredCount++;
                $document->owner?->notify(new DocumentActionNotification(
                    document: $document,
                    event: 'workflow_completed', // reuses generic templated message; add a dedicated 'expired' event if desired
                    comments: 'This document has expired as of ' . $document->expiry_date->toDateString() . '.',
                ));
            } elseif ($nowAging && ! $wasAging) {
                $agingCount++;
                $document->owner?->notify(new DocumentActionNotification(
                    document: $document,
                    event: 'stage_assigned',
                    comments: "This document expires in {$document->days_to_expiry} day(s) - renewal/revision may be needed.",
                ));
            }
        }

        $this->info("Lifecycle scan complete. Newly expired: {$expiredCount}. Newly aging: {$agingCount}.");
        return self::SUCCESS;
    }
}
