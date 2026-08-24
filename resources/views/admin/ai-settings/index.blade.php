<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-lg font-semibold text-gray-900">🤖 AI Settings</h3>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $settings->isConfigured() ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $settings->isConfigured() ? 'Configured' : 'Not configured' }}
                    </span>
                </div>
                <p class="text-sm text-gray-500 mb-4">
                    Powers the AI Compliance Pre-check, AI claim suggestions, AI revision-drafting
                    suggestions, and natural-language search. All four features stay hidden/fall back to
                    their non-AI behavior until a key is added here — nothing else changes.
                </p>

                <form method="POST" action="{{ route('admin.ai-settings.update') }}" class="space-y-5" x-data="{ provider: '{{ old('provider', $settings->provider ?? 'groq') }}' }">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="provider" value="Provider" />
                        <select id="provider" name="provider" x-model="provider" class="mt-1 block w-full border-gray-300 rounded-md">
                            <option value="groq">Groq (fast, free-tier friendly)</option>
                            <option value="anthropic">Anthropic (Claude)</option>
                        </select>
                    </div>

                    <div class="bg-brand-50 border border-brand-100 rounded-md p-4 text-xs text-brand-800">
                        <template x-if="provider === 'groq'">
                            <p>Create a free key at <span class="font-mono">console.groq.com/keys</span>, then paste it below.</p>
                        </template>
                        <template x-if="provider === 'anthropic'">
                            <p>Create a key at <span class="font-mono">console.anthropic.com</span> → API Keys, then paste it below.</p>
                        </template>
                    </div>

                    <div>
                        <x-input-label for="api_key" value="API Key" />
                        <x-text-input type="password" id="api_key" name="api_key" class="mt-1 block w-full" placeholder="{{ $settings->isConfigured() ? 'Leave blank to keep the current key' : 'Paste your API key' }}" />
                    </div>

                    <div>
                        <x-input-label for="model" value="Model" />
                        <x-text-input id="model" name="model" class="mt-1 block w-full" :value="old('model', $settings->model)" required />
                        <p class="mt-1 text-xs text-gray-500">
                            Groq examples: llama-3.3-70b-versatile, llama-3.1-8b-instant &middot; Anthropic: claude-sonnet-5
                        </p>
                    </div>

                    <x-primary-button>Save AI Settings</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Test Connection</h3>
                <form method="POST" action="{{ route('admin.ai-settings.test') }}">
                    @csrf
                    <x-primary-button>Send Test Prompt</x-primary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
