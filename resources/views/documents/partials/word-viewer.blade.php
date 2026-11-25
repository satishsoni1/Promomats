@php
    $version = $document->currentVersion;
@endphp
<div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 min-w-0">
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
             x-init="init()"
             @keydown.escape.window="full && toggleFull()"
             :class="full ? 'fixed inset-0 z-50 bg-white p-4 flex flex-col' : ''">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <h3 class="text-lg font-semibold text-gray-900">Review &amp; Track Changes <span x-show="full" x-cloak class="text-sm font-normal text-gray-400">— {{ $version->original_filename }} (v{{ $version->version_no }})</span></h3>
                <div class="flex items-center gap-2">
                    <a href="{{ $version->downloadUrl() }}" class="text-xs px-2.5 py-1 border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50 whitespace-nowrap" title="The .docx as saved, with its Word comments and tracked changes">⬇ Download with comments</a>
                    <button type="button" @click="toggleFull()" class="text-xs px-2.5 py-1 border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50 whitespace-nowrap" :title="full ? 'Exit full screen (Esc)' : 'Full screen'">
                        <span x-show="! full">⛶ Full screen</span><span x-show="full" x-cloak>✕ Exit full screen</span>
                    </button>
                </div>
            </div>
            <div class="mb-2 -mt-1">
                <span class="text-xs text-gray-500">
                    @if ($wordEditorRole === 'viewer')
                        {{ $document->legal_hold ? '⚖️ Read-only while under legal hold' : (auth()->user()->isAgencyUser() ? 'Read-only — comments and tracked changes are shown in the margin' : '🔒 Read-only — your stage has given its decision') }}
                    @elseif ($wordEditorRole === 'editor')
                        Comment, highlight and suggest changes — and accept or reject the stakeholders' tracked changes.
                    @else
                        Comment, highlight to annotate, and suggest changes. Every edit is recorded as a tracked change.
                    @endif
                </span>
            </div>

            <div x-show="loading" class="text-sm text-gray-500 py-8 text-center">Loading Word editor…</div>
            <div x-show="error" x-cloak x-text="error" class="text-sm text-amber-700 bg-amber-50 rounded-md p-3"></div>

            <div x-show="!error" :class="[loading ? 'invisible h-0 overflow-hidden' : '', full ? 'flex-1 min-h-0 flex flex-col' : '']">
                {{-- SuperDoc's page has a fixed width; keep it scrolling inside this box rather than
                     spilling over the sidebar. The toolbar is left unclipped so its dropdowns can open. --}}
                <div x-ref="toolbar" class="border border-gray-200 border-b-0 rounded-t bg-white min-w-0"></div>
                <div class="relative border border-gray-200 rounded-b bg-gray-100 overflow-auto" :class="full ? 'flex-1 min-h-0' : ''" :style="full ? '' : 'height: 75vh;'">
                    <div x-ref="editor" class="h-full"></div>
                </div>

                @if ($wordEditorRole !== 'viewer')
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <input type="text" x-model="changeNotes" placeholder="What did you change? (optional)"
                               class="flex-1 min-w-[12rem] text-sm border-gray-300 rounded-md">
                        <button type="button" @click="downloadCopy()" class="text-sm text-gray-600 hover:underline whitespace-nowrap" title="Includes your unsaved changes and comments">Download my copy</button>
                        <button type="button" @click="save()" :disabled="saving || !dirty"
                                class="text-sm text-white bg-brand-600 px-3 py-1.5 rounded-md hover:bg-brand-700 disabled:opacity-40 whitespace-nowrap">
                            <span x-show="!saving">Save as New Version</span>
                            <span x-show="saving" x-cloak>Saving…</span>
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-400" x-show="!dirty && !savedVersionNo">Select text → comment, or use the highlighter to annotate. Saving keeps all tracked changes and comments in the .docx, so they also open in desktop Word.</p>
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
