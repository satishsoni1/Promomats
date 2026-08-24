<?php

namespace App\Console\Commands;

use App\Models\RetrievalRequest;
use App\Services\RetrievalService;
use Illuminate\Console\Command;

/**
 * REQ-4.3: automatically works through pending cold-storage retrieval requests so
 * most never need the admin's manual "Process Now" override to meet the 24-hour SLA.
 * Runs frequently (every 10 minutes, see routes/console.php) since a restore is a
 * quick file copy, not a lengthy job.
 */
class ProcessRetrievalRequests extends Command
{
    protected $signature = 'documents:process-retrieval-requests';
    protected $description = 'Restores documents pending a cold storage retrieval request back onto primary storage.';

    public function handle(RetrievalService $service): int
    {
        $requests = RetrievalRequest::where('status', RetrievalRequest::STATUS_PENDING)->get();

        if ($requests->isEmpty()) {
            $this->info('No pending retrieval requests.');
            return self::SUCCESS;
        }

        foreach ($requests as $request) {
            $service->restore($request);
        }

        $this->info("Processed {$requests->count()} retrieval request(s).");
        return self::SUCCESS;
    }
}
