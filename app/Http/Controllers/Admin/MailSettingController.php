<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MailSettingController extends Controller
{
    public function index()
    {
        $settings = MailSetting::current();
        return view('admin.mail-settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'from_address' => ['required', 'email'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        $settings = MailSetting::current();

        $settings->fill([
            'mailer' => 'smtp',
            'host' => $validated['host'],
            'port' => $validated['port'],
            'username' => $validated['username'] ?? null,
            'encryption' => $validated['encryption'] ?? 'tls',
            'from_address' => $validated['from_address'],
            'from_name' => $validated['from_name'],
        ]);

        // Only overwrite the stored password if a new one was actually typed - the form
        // never re-displays the real value, so leaving it blank means "keep it as-is".
        if (filled($validated['password'] ?? null)) {
            $settings->password = $validated['password'];
        }

        $settings->save();

        return redirect()->route('admin.mail-settings.index')->with('status', 'Mail settings saved. Send a test email below to confirm they work.');
    }

    public function sendTest(Request $request)
    {
        $validated = $request->validate([
            'to' => ['required', 'email'],
        ]);

        // Re-apply settings for this request in case they were just saved - the
        // provider only runs once at boot, before this controller's own save() ran.
        $settings = MailSetting::current();
        if ($settings->isConfigured()) {
            config([
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port ?: 587,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.encryption' => $settings->encryption ?: 'tls',
                'mail.from.address' => $settings->from_address ?: config('mail.from.address'),
                'mail.from.name' => $settings->from_name ?: config('mail.from.name'),
            ]);
        }

        try {
            Mail::mailer('smtp')->raw(
                "This is a test email from VODO, sent " . now()->toDayDateTimeString() . ".\n\nIf you're reading this, your SMTP settings are working correctly.",
                function ($message) use ($validated, $settings) {
                    $message->to($validated['to'])
                        ->subject('VODO test email')
                        ->from($settings->from_address ?: config('mail.from.address'), $settings->from_name ?: config('mail.from.name'));
                }
            );

            return back()->with('status', "Test email sent to {$validated['to']}. Check the inbox (and spam folder).");
        } catch (\Throwable $e) {
            return back()->withErrors(['test' => 'Could not send test email: ' . $e->getMessage()]);
        }
    }
}
