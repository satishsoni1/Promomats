<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-lg font-semibold text-gray-900">Cold Storage Tier</h3>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $settings->enabled && $settings->isConfigured() ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $settings->enabled && $settings->isConfigured() ? 'Active' : ($settings->isConfigured() ? 'Configured — not enabled' : 'Not configured') }}
                    </span>
                </div>
                <p class="text-sm text-gray-500 mb-4">
                    Once a document has sat in <strong>Archived</strong> status longer than the window below, a daily
                    job moves its version files and reference attachments off primary storage onto this bucket to cut
                    storage cost, and repoints downloads there transparently. Works with any S3-compatible provider —
                    AWS S3, Backblaze B2, Wasabi, MinIO, and others — just point it at the right endpoint.
                </p>

                <div class="grid grid-cols-2 gap-4 mb-5 text-sm">
                    <div class="bg-gray-50 rounded-md p-3">
                        <p class="text-gray-500 text-xs uppercase">Already migrated</p>
                        <p class="text-xl font-semibold text-gray-900">{{ $migratedCount }} <span class="text-sm font-normal text-gray-500">document(s)</span></p>
                    </div>
                    <div class="bg-gray-50 rounded-md p-3">
                        <p class="text-gray-500 text-xs uppercase">Eligible for next run</p>
                        <p class="text-xl font-semibold text-gray-900">{{ $eligibleCount }} <span class="text-sm font-normal text-gray-500">document(s)</span></p>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.cold-storage-settings.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled)) class="rounded border-gray-300 text-brand-600">
                        Enable automatic migration to cold storage
                    </label>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="key" value="Access Key" />
                            <x-text-input id="key" name="key" class="mt-1 block w-full" :value="old('key', $settings->key)" required />
                        </div>
                        <div>
                            <x-input-label for="secret" value="Secret Key" />
                            <x-text-input type="password" id="secret" name="secret" class="mt-1 block w-full" placeholder="{{ $settings->isConfigured() ? 'Leave blank to keep current secret' : '' }}" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="bucket" value="Bucket" />
                            <x-text-input id="bucket" name="bucket" class="mt-1 block w-full" :value="old('bucket', $settings->bucket)" required />
                        </div>
                        <div>
                            <x-input-label for="region" value="Region" />
                            <x-text-input id="region" name="region" class="mt-1 block w-full" :value="old('region', $settings->region ?? 'us-east-1')" placeholder="us-east-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="endpoint" value="Custom Endpoint (optional — leave blank for AWS S3)" />
                        <x-text-input id="endpoint" name="endpoint" class="mt-1 block w-full" :value="old('endpoint', $settings->endpoint)" placeholder="https://s3.us-west-002.backblazeb2.com" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="use_path_style_endpoint" value="1" @checked(old('use_path_style_endpoint', $settings->use_path_style_endpoint)) class="rounded border-gray-300 text-brand-600">
                        Use path-style endpoint (needed for most non-AWS providers, e.g. MinIO)
                    </label>

                    <div>
                        <x-input-label for="days_after_archive" value="Migrate after this many days in Archived status" />
                        <x-text-input type="number" id="days_after_archive" name="days_after_archive" class="mt-1 block w-32" :value="old('days_after_archive', $settings->days_after_archive)" required />
                    </div>

                    <x-primary-button>Save Cold Storage Settings</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Test Connection</h3>
                <p class="text-sm text-gray-500 mb-4">Writes a small marker file to the bucket, reads it back, then deletes it — confirms the credentials actually work before anything real gets migrated.</p>
                <form method="POST" action="{{ route('admin.cold-storage-settings.test') }}">
                    @csrf
                    <x-primary-button>Test Connection</x-primary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
