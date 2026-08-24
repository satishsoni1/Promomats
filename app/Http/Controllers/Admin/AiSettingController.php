<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiSettingController extends Controller
{
    public function index()
    {
        $settings = AiSetting::current();
        return view('admin.ai-settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['groq', 'anthropic'])],
            'model' => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = AiSetting::current();
        $providerChanged = $settings->provider !== $validated['provider'];

        $settings->provider = $validated['provider'];
        $settings->model = $validated['model'];

        // Same "blank means keep the current one" pattern as Mail Settings - the form
        // never re-displays the real key. Switching provider always requires a fresh
        // key though, since an Anthropic key won't authenticate against Groq (or vice
        // versa) - clear the stale one rather than silently keep a key that can't work.
        if (filled($validated['api_key'] ?? null)) {
            $settings->api_key = $validated['api_key'];
        } elseif ($providerChanged) {
            $settings->api_key = null;
        }

        $settings->save();

        return redirect()->route('admin.ai-settings.index')->with('status',
            $providerChanged && blank($validated['api_key'] ?? null)
                ? "Switched to {$validated['provider']}. Add an API key for it below to activate AI features again."
                : 'AI settings saved. Test the connection below to confirm the key works.'
        );
    }

    public function testConnection(AiService $ai)
    {
        $result = $ai->testConnection();

        return $result['success']
            ? back()->with('status', $result['message'])
            : back()->withErrors(['test' => $result['message']]);
    }
}
