<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-lg font-semibold text-gray-900">Archiving Policy</h3>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $settings->enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $settings->enabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
                <p class="text-sm text-gray-500 mb-4">
                    Once enabled, a daily job automatically moves documents to <strong>Archived</strong>
                    after they've spent the configured number of days in a settled status (Approved,
                    Approved for Production/Distribution, Pending Expiration, Expired, Rejected,
                    Superseded, or Obsolete). Documents still moving through Draft, In Review, or
                    Revise &amp; Resubmit are never touched, regardless of age. The clock is measured
                    from when a document's status last changed, not when it was created.
                </p>

                <form method="POST" action="{{ route('admin.archiving-settings.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <label class="flex items-center gap-2 text-sm text-gray-800">
                        <input type="checkbox" name="enabled" value="1" @checked($settings->enabled) class="rounded border-gray-300 text-brand-600">
                        Automatically archive eligible documents
                    </label>

                    <div class="max-w-xs">
                        <x-input-label for="days_before_archive" value="Days before archiving" />
                        <x-text-input type="number" id="days_before_archive" name="days_before_archive" class="mt-1 block w-full" :value="old('days_before_archive', $settings->days_before_archive)" required min="1" />
                    </div>

                    <x-primary-button>Save Policy</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-900">Eligible Right Now</h3>
                    <span class="text-2xl font-bold text-gray-900" style="font-variant-numeric: tabular-nums;">{{ $eligibleCount }}</span>
                </div>
                <p class="text-xs text-gray-500 mb-3">The next scheduled run (or a manual <code class="bg-gray-100 px-1 rounded">php artisan documents:apply-archiving-policy</code>) will archive this many documents.</p>

                <h4 class="text-xs font-semibold text-gray-500 uppercase mt-4 mb-2">Closest to the threshold</h4>
                @forelse ($upcoming as $row)
                    <div class="flex items-center justify-between py-2 border-t border-gray-100 text-sm">
                        <div>
                            <a href="{{ route('documents.show', $row['document']) }}" class="font-medium text-brand-600 hover:underline">{{ $row['document']->title }}</a>
                            <span class="text-xs text-gray-500 ml-1">{{ $row['document']->statusLabel() }}</span>
                        </div>
                        <span class="text-xs text-gray-500" style="font-variant-numeric: tabular-nums;">{{ $row['days_in_status'] }} / {{ $settings->days_before_archive }} days</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No documents in a settled status yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
