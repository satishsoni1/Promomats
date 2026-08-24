<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">New Claim</h3>
                <form method="POST" action="{{ route('admin.claims.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="match_text" value="Claim Text" />
                        <x-text-input id="match_text" name="match_text" class="mt-1 block w-full" :value="old('match_text')" required autofocus placeholder="e.g. 45% reduction in risk of progression" />
                        <x-input-error :messages="$errors->get('match_text')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="body" value="Full Substantiation Text (optional)" />
                        <textarea id="body" name="body" rows="3" class="mt-1 block w-full border-gray-300 rounded-md">{{ old('body') }}</textarea>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="category" value="Category" />
                            <x-text-input id="category" name="category" class="mt-1 block w-full" :value="old('category')" placeholder="Efficacy, Safety…" />
                        </div>
                        <div>
                            <x-input-label for="product" value="Product" />
                            <x-text-input id="product" name="product" class="mt-1 block w-full" :value="old('product')" />
                        </div>
                        <div>
                            <x-input-label for="country" value="Country" />
                            <x-text-input id="country" name="country" class="mt-1 block w-full" :value="old('country')" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="products" value="Also applies to (products)" />
                            <x-text-input id="products" name="products" class="mt-1 block w-full" :value="old('products')" placeholder="Comma-separated, e.g. Natevba, Immunobooster" />
                        </div>
                        <div>
                            <x-input-label for="countries" value="Also applies to (countries)" />
                            <x-text-input id="countries" name="countries" class="mt-1 block w-full" :value="old('countries')" placeholder="Comma-separated, e.g. US, EU" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status" class="mt-1 block w-full border-gray-300 rounded-md">
                            <option value="draft" selected>Draft</option>
                            <option value="approved">Approved</option>
                        </select>
                    </div>

                    <div>
                        <x-input-label value="References" />
                        <p class="text-xs text-gray-500 mb-2">Source citations that substantiate this claim.</p>
                        <div class="space-y-2">
                            @for ($i = 0; $i < 3; $i++)
                                <div class="grid grid-cols-3 gap-2">
                                    <x-text-input name="reference_title[]" placeholder="Reference title" class="text-sm" />
                                    <x-text-input name="reference_citation[]" placeholder="Citation" class="text-sm" />
                                    <x-text-input name="reference_url[]" placeholder="URL (optional)" class="text-sm" />
                                </div>
                            @endfor
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create Claim</x-primary-button>
                        <a href="{{ route('admin.claims.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
