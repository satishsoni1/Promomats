<?php

namespace App\Console\Commands;

use App\Models\ArchivingSetting;
use App\Models\Document;
use App\Notifications\DocumentActionNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * REQ-4.1: automatically transitions documents to Archived once they've spent the
 * configured number of days (Admin > Archiving Settings, default 355) in a settled
 * status. Off by default - an admin has to opt in and set the window first.
 */
class ApplyArchivingPolicy extends Command
{
    protected $signature = 'documents:apply-archiving-policy';
    protected $description = 'Archives documents that have exceeded the configured archiving window in a settled status.';

    public function handle(): int
    {
        $settings = ArchivingSetting::current();

        if (! $settings->enabled) {
            $this->info('Archiving policy is disabled (Admin > Archiving Settings). Nothing to do.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($settings->days_before_archive);

        // Documents under legal hold are frozen - never auto-archived out from under
        // an active litigation/regulatory inquiry, no matter how long they've aged.
        $documents = Document::whereIn('status', Document::ARCHIVABLE_STATUSES)
            ->where('status_changed_at', '<=', $cutoff)
            ->where('legal_hold', false)
            ->get();

        $audit = app(AuditLogger::class);

        foreach ($documents as $document) {
            $previousStatus = $document->statusLabel();
            $oldStatus = $document->status;
            $document->update(['status' => 'archived']);

            $audit->record(
                action: 'ARCHIVED',
                document: $document,
                oldStatus: $oldStatus,
                newStatus: 'archived',
                description: "Automatically archived after {$settings->days_before_archive} days in \"{$previousStatus}\" status.",
            );

            $document->owner?->notify(new DocumentActionNotification(
                document: $document,
                event: 'lifecycle_changed',
                comments: "Automatically archived after {$settings->days_before_archive} days in \"{$previousStatus}\" status.",
            ));
        }

        $this->info("Archiving policy applied. Newly archived: {$documents->count()}.");
        return self::SUCCESS;
    }
}
