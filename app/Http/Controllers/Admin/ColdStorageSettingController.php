<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ColdStorageSetting;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ColdStorageSettingController extends Controller
{
    public function index()
    {
        $settings = ColdStorageSetting::current();

        $migratedCount = Document::whereHas('versions', fn ($q) => $q->where('disk', 'cold_storage'))->count();
        $eligibleCount = $settings->enabled
            ? Document::where('status', 'archived')
                ->where('status_changed_at', '<=', now()->subDays($settings->days_after_archive))
                ->whereDoesntHave('versions', fn ($q) => $q->where('disk', 'cold_storage'))
                ->count()
            : 0;

        return view('admin.cold-storage-settings.index', compact('settings', 'migratedCount', 'eligibleCount'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'key' => ['required', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:120'],
            'bucket' => ['required', 'string', 'max:255'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            'use_path_style_endpoint' => ['nullable', 'boolean'],
            'days_after_archive' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $settings = ColdStorageSetting::current();

        $settings->fill([
            'enabled' => $request->boolean('enabled'),
            'key' => $validated['key'],
            'region' => $validated['region'] ?? null,
            'bucket' => $validated['bucket'],
            'endpoint' => $validated['endpoint'] ?? null,
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'days_after_archive' => $validated['days_after_archive'],
        ]);

        // Same "blank means keep as-is" convention as Mail/AI Settings - the form never
        // re-displays the real secret.
        if (filled($validated['secret'] ?? null)) {
            $settings->secret = $validated['secret'];
        }

        $settings->save();

        return redirect()->route('admin.cold-storage-settings.index')->with('status', 'Cold storage settings saved. Run "Test Connection" below to confirm they work.');
    }

    /**
     * Writes, reads back, then deletes a small marker object directly against the
     * submitted (or already-saved) credentials - proves read+write+delete all work
     * against the real bucket before anything real ever gets migrated onto it.
     */
    public function test(Request $request)
    {
        $settings = ColdStorageSetting::current();

        if (! $settings->isConfigured()) {
            return back()->withErrors(['test' => 'Save a key, secret, and bucket before testing the connection.']);
        }

        $diskName = 'cold_storage_test';
        config(["filesystems.disks.{$diskName}" => $settings->toDiskConfig()]);

        $marker = 'vodo-cold-storage-test-' . now()->timestamp . '.txt';

        try {
            $disk = Storage::disk($diskName);
            $disk->put($marker, 'VODO cold storage connection test - ' . now()->toDayDateTimeString());
            $readBack = $disk->get($marker);
            $disk->delete($marker);

            if ($readBack === null) {
                throw new \RuntimeException('Wrote the test object but could not read it back.');
            }

            return back()->with('status', 'Connection test passed — write, read, and delete all succeeded against the bucket.');
        } catch (\Throwable $e) {
            return back()->withErrors(['test' => 'Connection test failed: ' . $e->getMessage()]);
        }
    }
}
