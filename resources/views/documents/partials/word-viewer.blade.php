@php
    $version = $document->currentVersion;
@endphp
<div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4">
    @if ($version->extension() === 'docx')
        {{-- In-browser Word review with SuperDoc - see resources/js/word-editor.js --}}
        <div x-data="wordReviewEditor({
                 fileUrl: '{{ $version->viewUrl() }}',
                 saveUrl: '{{ route('documents.word-review.store', $document) }}',
                 csrfToken: '{{ csrf_token() }}',
                 baseVersionId: {{ $version->id }},
                 role: '{{ $wordEditorRole }}',
                 user: {{ \Illuminate\Support\Js::from(['id' => (string) auth()->id(), 'name' => auth()->user()->name, 'email' => auth()->user()->email]) }},
                 filename: {{ \Illuminate\Support\Js::from($version->original_filename) }},
             })"
             x-init="init()">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <h3 class="text-lg font-semibold text-gray-900">Review &amp; Track Changes</h3>
                <span class="text-xs text-gray-500">
                    @if ($wordEditorRole === 'viewer')
                        ⚖️ Read-only while under legal hold
                    @elseif ($wordEditorRole === 'editor')
                        You can edit directly, switch to Suggesting to track your changes, and accept or reject others' changes.
                    @else
                        Your edits are recorded as tracked changes for the owner to accept or reject.
                    @endif
                </span>
            </div>

            <div x-show="loading" class="text-sm text-gray-500 py-8 text-center">Loading Word editor…</div>
            <div x-show="error" x-cloak x-text="error" class="text-sm text-amber-700 bg-amber-50 rounded-md p-3"></div>

            <div x-show="!error" :class="loading ? 'invisible h-0 overflow-hidden' : ''">
                <div x-ref="toolbar" class="border border-gray-200 border-b-0 rounded-t bg-white"></div>
                <div class="border border-gray-200 rounded-b bg-gray-100" style="height: 75vh;">
                    <div x-ref="editor" class="h-full"></div>
                </div>

                @if ($wordEditorRole !== 'viewer')
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <input type="text" x-model="changeNotes" placeholder="What did you change? (optional)"
                               class="flex-1 min-w-[12rem] text-sm border-gray-300 rounded-md">
                        <button type="button" @click="downloadCopy()" class="text-sm text-gray-600 hover:underline whitespace-nowrap">Download copy</button>
                        <button type="button" @click="save()" :disabled="saving || !dirty"
                                class="text-sm text-white bg-brand-600 px-3 py-1.5 rounded-md hover:bg-brand-700 disabled:opacity-40 whitespace-nowrap">
                            <span x-show="!saving">Save as New Version</span>
                            <span x-show="saving" x-cloak>Saving…</span>
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-400" x-show="!dirty && !savedVersionNo">Add comments via right-click or select text → comment. Saving keeps all tracked changes and comments in the .docx, so they also open in desktop Word.</p>
                    <p class="mt-1 text-xs text-amber-700" x-show="dirty && !saving" x-cloak>Unsaved changes.</p>
                    <p class="mt-1 text-xs text-green-700" x-show="savedVersionNo" x-cloak x-text="'Saved as v' + savedVersionNo + ' — reloading…'"></p>
                    <div x-show="saveError" x-cloak x-text="saveError" class="mt-2 text-xs text-red-800 bg-red-50 border border-red-200 rounded-md p-2"></div>
                @endif
            </div>
        </div>
    @else
        <h3 class="text-lg font-semibold text-gray-900 mb-2">Preview</h3>
        <p class="text-sm text-gray-500">In-browser review works with .docx files. Download this {{ strtoupper($version->extension()) }} file from the Versions list below, or save it as .docx in Word and upload it as a new version to review it here.</p>
    @endif
</div>
