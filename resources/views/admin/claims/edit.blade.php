<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Edit Claim</h3>
                    <a href="{{ route('admin.claims.show', $claim) }}" class="text-sm text-brand-600 hover:underline">View "Where Used" &rarr;</a>
                </div>
                <form method="POST" action="{{ route('admin.claims.update', $claim) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="match_text" value="Claim Text" />
                        <x-text-input id="match_text" name="match_text" class="mt-1 block w-full" :value="old('match_text', $claim->match_text)" required />
                        <x-input-error :messages="$errors->get('match_text')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="body" value="Full Substantiation Text" />
                        <textarea id="body" name="body" rows="3" class="mt-1 block w-full border-gray-300 rounded-md">{{ old('body', $claim->body) }}</textarea>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="category" value="Category" />
                            <x-text-input id="category" name="category" class="mt-1 block w-full" :value="old('category', $claim->category)" />
                        </div>
                        <div>
                            <x-input-label for="product" value="Product" />
                            <x-text-input id="product" name="product" class="mt-1 block w-full" :value="old('product', $claim->product)" />
                        </div>
                        <div>
                            <x-input-label for="country" value="Country" />
                            <x-text-input id="country" name="country" class="mt-1 block w-full" :value="old('country', $claim->country)" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="products" value="Also applies to (products)" />
                            <x-text-input id="products" name="products" class="mt-1 block w-full" :value="old('products', is_array($claim->products) ? implode(', ', $claim->products) : null)" />
                        </div>
                        <div>
                            <x-input-label for="countries" value="Also applies to (countries)" />
                            <x-text-input id="countries" name="countries" class="mt-1 block w-full" :value="old('countries', is_array($claim->countries) ? implode(', ', $claim->countries) : null)" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status" class="mt-1 block w-full border-gray-300 rounded-md">
                            @foreach (['draft','approved','expired'] as $s)
                                <option value="{{ $s }}" @selected(old('status', $claim->status) === $s)>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label value="References" />
                        <div class="space-y-2">
                            @for ($i = 0; $i < max(3, $claim->references->count()); $i++)
                                @php $ref = $claim->references->get($i); @endphp
                                <div class="grid grid-cols-3 gap-2">
                                    <x-text-input name="reference_title[]" placeholder="Reference title" class="text-sm" :value="$ref?->title" />
                                    <x-text-input name="reference_citation[]" placeholder="Citation" class="text-sm" :value="$ref?->citation" />
                                    <x-text-input name="reference_url[]" placeholder="URL (optional)" class="text-sm" :value="$ref?->url" />
                                </div>
                            @endfor
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Save Changes</x-primary-button>
                        <a href="{{ route('admin.claims.index') }}" class="text-sm text-gray-500 hover:underline">Back to list</a>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 mt-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-900">Reference Attachments ({{ $claim->referenceAttachments->count() }})</h3>
                    <button type="button" onclick="document.getElementById('upload-claim-reference-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ Attach reference file</button>
                </div>
                <p class="text-xs text-gray-500 mb-3">Actual uploaded substantiation files (a study PDF, a certificate) — separate from the citation-only references above.</p>

                <form id="upload-claim-reference-form" method="POST" action="{{ route('admin.claims.references.store', $claim) }}" enctype="multipart/form-data" class="hidden mb-4 p-4 bg-gray-50 rounded-md space-y-3">
                    @csrf
                    <input type="text" name="title" required placeholder="Reference title" class="block w-full text-sm border-gray-300 rounded-md">
                    <input type="text" name="category" placeholder="Category (optional, e.g. Study, Certificate, Legal Template)" class="block w-full text-sm border-gray-300 rounded-md">
                    <input type="file" name="file" required class="block w-full text-sm">
                    <x-primary-button>Attach</x-primary-button>
                </form>

                @forelse ($claim->referenceAttachments as $reference)
                    <div class="flex items-center justify-between py-2 border-t border-gray-100 first:border-t-0 text-sm">
                        <div>
                            <span class="text-gray-900">{{ $reference->title }}</span>
                            @if ($reference->category)
                                <span class="text-xs px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded ml-1">{{ $reference->category }}</span>
                            @endif
                            <span class="text-xs text-gray-500 ml-1">{{ $reference->original_filename }} &middot; {{ $reference->humanFileSize() }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="{{ $reference->downloadUrl() }}" class="text-brand-600 hover:underline text-xs">Download</a>
                            <form method="POST" action="{{ route('admin.claims.references.destroy', [$claim, $reference]) }}" onsubmit="return confirm('Remove this reference file?');">
                                @csrf @method('DELETE')
                                <button class="text-xs text-gray-400 hover:text-red-600">&times;</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No reference files attached yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
