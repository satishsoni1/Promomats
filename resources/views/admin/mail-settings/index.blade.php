<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-lg font-semibold text-gray-900">Outbound Mail (Gmail SMTP)</h3>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $settings->isConfigured() ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $settings->isConfigured() ? 'Configured' : 'Not configured — using fallback' }}
                    </span>
                </div>
                <p class="text-sm text-gray-500 mb-4">
                    Every notification email in VODO (stage assignments, decisions, comments, overdue
                    reminders, account updates...) goes out through this configuration. Until it's filled
                    in, mail falls back to whatever <code class="bg-gray-100 px-1 rounded">MAIL_MAILER</code>
                    is set to in <code class="bg-gray-100 px-1 rounded">.env</code> (by default, emails are
                    only written to the log file, not actually sent).
                </p>

                <div class="bg-brand-50 border border-brand-100 rounded-md p-4 text-xs text-brand-800 mb-5 space-y-1">
                    <p class="font-semibold">To use a Google Gmail account:</p>
                    <ol class="list-decimal list-inside space-y-0.5">
                        <li>Turn on 2-Step Verification on the Google account (required for SMTP).</li>
                        <li>Generate an App Password at <span class="font-mono">myaccount.google.com/apppasswords</span> (choose "Mail" as the app).</li>
                        <li>Use that 16-character App Password below — not the account's normal login password.</li>
                        <li>Host/port/encryption below are already set to Gmail's values.</li>
                    </ol>
                </div>

                <form method="POST" action="{{ route('admin.mail-settings.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="host" value="SMTP Host" />
                            <x-text-input id="host" name="host" class="mt-1 block w-full" :value="old('host', $settings->host ?? 'smtp.gmail.com')" required />
                        </div>
                        <div>
                            <x-input-label for="port" value="Port" />
                            <x-text-input type="number" id="port" name="port" class="mt-1 block w-full" :value="old('port', $settings->port ?? 587)" required />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="username" value="Gmail Address" />
                            <x-text-input id="username" name="username" class="mt-1 block w-full" :value="old('username', $settings->username)" placeholder="you@gmail.com" />
                        </div>
                        <div>
                            <x-input-label for="password" value="App Password" />
                            <x-text-input type="password" id="password" name="password" class="mt-1 block w-full" placeholder="{{ $settings->password ? 'Leave blank to keep current password' : '16-character app password' }}" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="encryption" value="Encryption" />
                            <select id="encryption" name="encryption" class="mt-1 block w-full border-gray-300 rounded-md">
                                <option value="tls" @selected(old('encryption', $settings->encryption ?? 'tls') === 'tls')>TLS (port 587)</option>
                                <option value="ssl" @selected(old('encryption', $settings->encryption) === 'ssl')>SSL (port 465)</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="from_address" value="\"From\" Address" />
                            <x-text-input type="email" id="from_address" name="from_address" class="mt-1 block w-full" :value="old('from_address', $settings->from_address)" placeholder="notifications@yourcompany.com" required />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="from_name" value="\"From\" Name" />
                        <x-text-input id="from_name" name="from_name" class="mt-1 block w-full" :value="old('from_name', $settings->from_name ?? config('app.name'))" required />
                    </div>

                    <x-primary-button>Save Mail Settings</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Send a Test Email</h3>
                <form method="POST" action="{{ route('admin.mail-settings.test') }}" class="flex items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input-label for="to" value="Send to" />
                        <x-text-input type="email" id="to" name="to" class="mt-1 block w-full" value="{{ auth()->user()->email }}" required />
                    </div>
                    <x-primary-button>Send Test</x-primary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
