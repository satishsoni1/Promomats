<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArchivingSetting;
use App\Models\Document;
use Illuminate\Http\Request;

class ArchivingSettingController extends Controller
{
    public function index()
    {
        $settings = ArchivingSetting::current();

        $cutoff = now()->subDays($settings->days_before_archive);
        $eligibleCount = Document::whereIn('status', Document::ARCHIVABLE_STATUSES)
            ->where('status_changed_at', '<=', $cutoff)
            ->count();

        $upcoming = Document::whereIn('status', Document::ARCHIVABLE_STATUSES)
            ->with('owner')
            ->orderBy('status_changed_at')
            ->limit(10)
            ->get()
            ->map(fn ($d) => [
                'document' => $d,
                'days_in_status' => (int) $d->status_changed_at?->diffInDays(now()),
            ]);

        return view('admin.archiving-settings.index', compact('settings', 'eligibleCount', 'upcoming'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'days_before_archive' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        ArchivingSetting::current()->update([
            'enabled' => $request->boolean('enabled'),
            'days_before_archive' => $validated['days_before_archive'],
        ]);

        return redirect()->route('admin.archiving-settings.index')->with('status', 'Archiving policy saved.');
    }
}
