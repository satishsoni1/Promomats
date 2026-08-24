<?php

namespace App\Console\Commands;

use App\Models\ColdStorageSetting;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * REQ-4.2: once a document has been Archived for the configured window (Admin >
 * Cold Storage Settings, default 30 days), streams its version files and
 * reference attachments off the hot 'documents' disk onto the configured
 * S3-compatible 'cold_storage' disk, then repoints disk/file_path on each row.
 * No controller changes were needed for this - download/view already resolve
 * dynamically via Storage::disk($version->disk).
 */
class MigrateToColdStorage extends Command
{
    protected $signature = 'documents:migrate-to-cold-storage';
    protected $description = "Moves archived documents' files (versions + reference attachments) to the configured cold storage tier.";

    public function handle(): int
    {
        $settings = ColdStorageSetting::current();

        if (! $settings->enabled || ! $settings->isConfigured()) {
            $this->info('Cold storage is disabled or not fully configured (Admin > Cold Storage Settings). Nothing to do.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($settings->days_after_archive);

        $documents = Document::where('status', 'archived')
            ->where('status_changed_at', '<=', $cutoff)
            ->with(['versions', 'referenceAttachments'])
            ->get();

        $movedFiles = 0;
        $failedDocuments = 0;

        foreach ($documents as $document) {
            try {
                foreach ($document->versions as $version) {
                    if ($version->disk === 'cold_storage') {
                        continue;
                    }
                    $this->migrateFile($version, "documents/{$document->id}/versions/" . basename($version->file_path));
                    $movedFiles++;
                }

                foreach ($document->referenceAttachments as $reference) {
                    if ($reference->disk === 'cold_storage') {
                        continue;
                    }
                    $this->migrateFile($reference, "documents/{$document->id}/references/" . basename($reference->file_path));
                    $movedFiles++;
                }
            } catch (\Throwable $e) {
                $failedDocuments++;
                $this->error("Failed to migrate document #{$document->id} ({$document->title}): {$e->getMessage()}");
            }
        }

        $this->info("Cold storage migration complete. Files moved: {$movedFiles}. Documents with failures: {$failedDocuments}.");
        return self::SUCCESS;
    }

    /**
     * Streams (never loads the whole file into memory - matters for multi-GB
     * video/print artwork) from the model's current disk to cold_storage, verifies
     * the copy landed, only then deletes the hot-storage original and repoints the
     * row. Ordering the delete after the verified copy avoids ever losing a file to
     * a mid-migration failure.
     */
    protected function migrateFile($model, string $newPath): void
    {
        $sourceDisk = Storage::disk($model->disk);
        $destDisk = Storage::disk('cold_storage');

        if (! $sourceDisk->exists($model->file_path)) {
            throw new \RuntimeException("Source file missing at {$model->disk}:{$model->file_path}");
        }

        $stream = $sourceDisk->readStream($model->file_path);
        $destDisk->put($newPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $destDisk->exists($newPath)) {
            throw new \RuntimeException("Copy to cold storage did not verify at {$newPath}");
        }

        $sourceDisk->delete($model->file_path);

        $model->update(['disk' => 'cold_storage', 'file_path' => $newPath]);
    }
}
