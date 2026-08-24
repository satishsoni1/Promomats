<?php

namespace App\Services;

use App\Models\RetrievalRequest;
use App\Notifications\DocumentActionNotification;
use Illuminate\Support\Facades\Storage;

/**
 * REQ-4.3: restores a document's cold-stored files back onto primary ('documents')
 * storage. Shared by the admin "Process Now" action and the scheduled
 * documents:process-retrieval-requests command, so both paths behave identically.
 */
class RetrievalService
{
    public function restore(RetrievalRequest $request): void
    {
        $request->update(['status' => RetrievalRequest::STATUS_IN_PROGRESS]);

        $document = $request->document()->with(['versions', 'referenceAttachments'])->first();

        try {
            $restoredCount = 0;

            foreach ($document->versions as $version) {
                if ($version->disk !== 'cold_storage') {
                    continue;
                }
                $this->restoreFile($version, "documents/{$document->id}/versions/" . basename($version->file_path));
                $restoredCount++;
            }

            foreach ($document->referenceAttachments as $reference) {
                if ($reference->disk !== 'cold_storage') {
                    continue;
                }
                $this->restoreFile($reference, "documents/{$document->id}/references/" . basename($reference->file_path));
                $restoredCount++;
            }

            $request->update([
                'status' => RetrievalRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'notes' => "{$restoredCount} file(s) restored.",
            ]);

            $request->requestedBy?->notify(new DocumentActionNotification(
                document: $document,
                event: 'retrieval_completed',
            ));
        } catch (\Throwable $e) {
            $request->update([
                'status' => RetrievalRequest::STATUS_FAILED,
                'notes' => $e->getMessage(),
            ]);

            $request->requestedBy?->notify(new DocumentActionNotification(
                document: $document,
                event: 'retrieval_failed',
                comments: $e->getMessage(),
            ));
        }
    }

    /**
     * Same verified streaming copy-then-delete pattern as
     * MigrateToColdStorage::migrateFile(), reversed: cold_storage -> documents.
     */
    protected function restoreFile($model, string $newPath): void
    {
        $sourceDisk = Storage::disk('cold_storage');
        $destDisk = Storage::disk('documents');

        if (! $sourceDisk->exists($model->file_path)) {
            throw new \RuntimeException("Cold storage file missing at {$model->file_path}");
        }

        $stream = $sourceDisk->readStream($model->file_path);
        $destDisk->put($newPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $destDisk->exists($newPath)) {
            throw new \RuntimeException("Restore copy did not verify at {$newPath}");
        }

        $sourceDisk->delete($model->file_path);

        $model->update(['disk' => 'documents', 'file_path' => $newPath]);
    }
}
