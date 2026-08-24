@php
    $version = $document->currentVersion;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">✏️ Edit PDF Content</h2>
                <p class="text-sm text-gray-500">{{ $document->title }}</p>
            </div>
            <a href="{{ route('documents.show', $document) }}" class="text-sm text-gray-500 hover:underline">&larr; Back to document</a>
        </div>
    </x-slot>

    <div class="py-8"
         x-data="pdfContentEditor({
             pdfUrl: '{{ $version->viewUrl() }}',
             csrfToken: '{{ csrf_token() }}',
             storeUrl: '{{ route('documents.pdf-edits.store', $document) }}',
             originalFilename: {{ \Illuminate\Support\Js::from($version->original_filename) }},
         })"
         x-init="init()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="bg-amber-50 border border-amber-200 text-amber-900 text-sm rounded-lg p-4 mb-4">
                Edits here are real changes to the PDF's content. Nothing is written until you click <strong>Save as New Version</strong> — that creates a brand-new version (the current one stays exactly as it was), with every edit permanently attributed to you and visible in the document's Content Edits history.
            </div>

            <div x-show="saveError" x-cloak x-text="saveError" class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg p-4 mb-4"></div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <div class="lg:col-span-3 bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-3 text-sm flex-wrap gap-2">
                        <div class="flex items-center gap-1">
                            <button type="button" @click="prevPage()" :disabled="pageNum <= 1" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40">‹</button>
                            <span class="text-gray-600 text-xs whitespace-nowrap">Page <span x-text="pageNum"></span> of <span x-text="numPages"></span></span>
                            <button type="button" @click="nextPage()" :disabled="pageNum >= numPages" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40">›</button>
                            <button type="button" @click="zoomOut()" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 ml-2">−</button>
                            <button type="button" @click="zoomIn()" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">+</button>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="setMode('add_text')"
                                    :class="editMode === 'add_text' ? 'bg-amber-500 text-white border-amber-500' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                                    class="px-3 py-1.5 border rounded-md text-xs font-medium">✏️ Add Text</button>
                            <button type="button" @click="setMode('redact')"
                                    :class="editMode === 'redact' ? 'bg-red-700 text-white border-red-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                                    class="px-3 py-1.5 border rounded-md text-xs font-medium">⬛ Redact</button>
                        </div>
                    </div>

                    <p class="text-xs mb-2" x-show="editMode === 'add_text'" x-cloak class="text-amber-700">Click anywhere on the page to place text.</p>
                    <p class="text-xs mb-2" x-show="editMode === 'redact'" x-cloak class="text-red-700">Drag a box over the content you want covered/removed.</p>

                    <div x-show="loading" class="text-sm text-gray-500 py-12 text-center">Loading PDF…</div>
                    <div x-show="error" x-cloak x-text="error" class="text-sm text-red-700 bg-red-50 rounded-md p-3"></div>

                    {{--
                        Two nested wrappers, not one, and this split matters: the OUTER
                        div is the scroll/clip viewport (overflow-auto + max-height) - its
                        own box is only ever as tall as 78vh, even though the canvas inside
                        can be much taller. The INNER div is what's actually
                        `position:relative` and sized to the canvas's full natural
                        dimensions (inline-block, no height cap of its own) - it's the
                        containing block every `left:X%; top:Y%` overlay below resolves
                        against. Collapsing this into one div (as an earlier version did)
                        made every percentage resolve against the *clipped* 78vh box
                        instead of the full page, so on any PDF tall/zoomed enough to need
                        scrolling, a click's page-relative % (correct) and the overlay's
                        rendered position (resolved against the wrong, shorter box) came
                        apart - edits landed nowhere near where you clicked.
                    --}}
                    <div x-show="!loading && !error" x-cloak
                         class="border border-gray-200 rounded overflow-auto max-w-full bg-gray-50" style="max-height: 78vh;">
                        <div class="relative inline-block">
                            <canvas x-ref="canvas"
                                    @click="onCanvasClick($event)"
                                    @mousedown="onCanvasMouseDown($event)"
                                    @mousemove="onCanvasMouseMove($event)"
                                    @mouseup="onCanvasMouseUp($event)"
                                    :class="editMode === 'redact' ? 'cursor-crosshair' : (editMode === 'add_text' ? 'cursor-text' : 'cursor-default')"
                                    class="block"></canvas>

                            <!-- live drag preview -->
                            <div x-show="dragStart && dragCurrent" x-cloak
                                 class="absolute border-2 border-red-700 bg-red-700/30 pointer-events-none"
                                 :style="dragRectStyle()"></div>

                            <!-- committed pending edits on the current page -->
                            <template x-for="edit in editsForPage()" :key="edit._id">
                                <div class="absolute pointer-events-none"
                                     :style="edit.edit_type === 'redact'
                                        ? `left:${edit.x}%; top:${edit.y}%; width:${edit.width}%; height:${edit.height}%; background: rgba(140,20,20,0.55); border: 2px solid rgba(90,10,10,0.8);`
                                        : `left:${edit.x}%; top:${edit.y}%;`">
                                    <span x-show="edit.edit_type === 'add_text'" x-cloak
                                          class="inline-block bg-amber-200 border border-amber-500 text-amber-900 text-xs px-1 rounded -translate-y-full whitespace-nowrap"
                                          x-text="edit.content"></span>
                                </div>
                            </template>

                            <!-- text composer -->
                            <div x-show="textComposer" x-cloak
                                 class="absolute z-20 w-64 bg-white border border-amber-300 rounded-lg shadow-lg p-3"
                                 :style="textComposer ? `left:${textComposer.x}%; top:${textComposer.y}%;` : ''">
                                <textarea x-model="textValue" rows="2" placeholder="Text to add…" class="w-full text-xs border-gray-300 rounded mb-2" autofocus></textarea>
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="cancelComposer()" class="text-xs text-gray-500">Cancel</button>
                                    <button type="button" @click="submitTextEdit()" class="text-xs text-white bg-amber-600 px-2 py-1 rounded">Place</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-2">Pending Edits (<span x-text="pendingEdits.length"></span>)</h3>
                        <template x-if="pendingEdits.length === 0">
                            <p class="text-xs text-gray-500">Use Add Text or Redact above, then edits will appear here before you save.</p>
                        </template>
                        <div class="space-y-2 max-h-64 overflow-y-auto">
                            <template x-for="edit in pendingEdits" :key="edit._id">
                                <div class="flex items-start justify-between gap-2 text-xs border border-gray-100 rounded p-2">
                                    <div class="min-w-0">
                                        <span :class="edit.edit_type === 'redact' ? 'text-red-700' : 'text-amber-700'" x-text="edit.edit_type === 'redact' ? 'Redact' : 'Add text'" class="font-medium"></span>
                                        <span class="text-gray-400">p.<span x-text="edit.page_number"></span></span>
                                        <p x-show="edit.content" x-text="edit.content" class="text-gray-700 truncate"></p>
                                    </div>
                                    <button type="button" @click="removeEdit(edit._id)" class="text-gray-400 hover:text-red-600 shrink-0">&times;</button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4">
                        <label class="block text-xs text-gray-500 mb-1">Change notes (optional)</label>
                        <textarea x-model="changeNotes" rows="2" placeholder="Auto-summarized if left blank…" class="w-full text-xs border-gray-300 rounded-md mb-3"></textarea>
                        <button type="button" @click="saveEdits()" :disabled="pendingEdits.length === 0 || saving"
                                class="w-full text-sm text-white bg-brand-600 px-3 py-2 rounded-md hover:bg-brand-700 disabled:opacity-40">
                            <span x-show="!saving">Save as New Version</span>
                            <span x-show="saving" x-cloak>Saving…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
