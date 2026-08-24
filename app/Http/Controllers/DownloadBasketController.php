<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class DownloadBasketController extends Controller
{
    protected const SESSION_KEY = 'download_basket';

    public function add(Request $request, Document $document)
    {
        $basket = collect(session(self::SESSION_KEY, []))->push($document->id)->unique()->values()->all();
        session([self::SESSION_KEY => $basket]);

        return back()->with('status', "\"{$document->title}\" added to your download basket.");
    }

    public function remove(Request $request, Document $document)
    {
        $basket = collect(session(self::SESSION_KEY, []))->reject(fn ($id) => $id === $document->id)->values()->all();
        session([self::SESSION_KEY => $basket]);

        return back()->with('status', 'Removed from basket.');
    }

    public function clear()
    {
        session()->forget(self::SESSION_KEY);
        return back()->with('status', 'Basket cleared.');
    }

    public function show()
    {
        $documents = Document::with('currentVersion')
            ->whereIn('id', session(self::SESSION_KEY, []))
            ->get();

        return view('basket.show', compact('documents'));
    }

    /**
     * Stream every basketed document's current version as a single zip - the
     * "bulk download" action behind PromoMats' cart icon.
     */
    public function download(): StreamedResponse
    {
        $documents = Document::with('currentVersion')
            ->whereIn('id', session(self::SESSION_KEY, []))
            ->get()
            ->filter(fn ($d) => $d->currentVersion);

        abort_if($documents->isEmpty(), 404, 'Your download basket is empty.');

        $zipPath = tempnam(sys_get_temp_dir(), 'vodo_basket_') . '.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($documents as $document) {
            $version = $document->currentVersion;
            $disk = Storage::disk($version->disk);

            if ($disk->exists($version->file_path)) {
                $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $document->reference_no . '_' . $version->original_filename);
                $zip->addFile($disk->path($version->file_path), $safeName);
            }
        }

        $zip->close();

        return response()->streamDownload(function () use ($zipPath) {
            echo file_get_contents($zipPath);
            unlink($zipPath);
        }, 'vodo-documents-' . now()->format('Ymd-His') . '.zip');
    }
}
