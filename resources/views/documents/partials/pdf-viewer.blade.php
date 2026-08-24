@php
    $version = $document->currentVersion;
@endphp
<div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4"
     x-data="pdfAnnotationViewer({
         pdfUrl: '{{ $version->viewUrl() }}',
         csrfToken: '{{ csrf_token() }}',
         storeUrl: '{{ route('documents.comments.store', $document) }}',
         initialAnnotations: {{ \Illuminate\Support\Js::from($pdfAnnotationsByPage) }},
         mappingStoreUrl: '{{ route('documents.claim-reference-mappings.store', $document) }}',
         mappingDestroyUrlBase: '{{ url('/documents/' . $document->id . '/claim-reference-mappings/') }}/',
         documentClaims: {{ \Illuminate\Support\Js::from($document->claims->map(fn ($c) => ['id' => $c->id, 'match_text' => $c->match_text])) }},
         documentReferences: {{ \Illuminate\Support\Js::from($document->referenceAttachments->map(fn ($r) => ['id' => $r->id, 'title' => $r->title])) }},
         initialMappings: {{ \Illuminate\Support\Js::from($claimReferenceMappingsByPage ?? []) }},
         pdfEditStoreUrl: '{{ route('documents.pdf-edits.store', $document) }}',
         originalFilename: {{ \Illuminate\Support\Js::from($version->original_filename) }},
     })"
     x-init="init()">

    <div class="flex items-center justify-between mb-3 text-sm">
        <h3 class="text-lg font-semibold text-gray-900">Preview &amp; Annotations</h3>
        <div class="flex items-center gap-3" x-show="!loading && !error" x-cloak>
            <div class="flex items-center gap-1">
                <button type="button" @click="prevPage()" :disabled="pageNum <= 1"
                        class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40 disabled:hover:bg-transparent">‹</button>
                <span class="text-gray-600 text-xs whitespace-nowrap">Page <span x-text="pageNum"></span> of <span x-text="numPages"></span></span>
                <button type="button" @click="nextPage()" :disabled="pageNum >= numPages"
                        class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40 disabled:hover:bg-transparent">›</button>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" @click="zoomOut()" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">−</button>
                <button type="button" @click="zoomIn()" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">+</button>
            </div>
            {{-- Single mode control: replaces what used to be an always-on comment
                 behavior + a separate 'Map claim ↔ reference' toggle button + a
                 whole separate 'Edit PDF Content' page. --}}
            <div class="relative">
                <button type="button" @click="modeMenuOpen = !modeMenuOpen"
                        class="px-2 py-1 border border-gray-200 rounded text-xs whitespace-nowrap flex items-center gap-1 hover:bg-gray-50">
                    <span x-text="mode === 'edit' ? '✏️ Edit' : (mode === 'reference' ? '🔗 Reference' : '💬 Comment')"></span>
                    <span class="text-gray-400">▾</span>
                </button>
                <div x-show="modeMenuOpen" x-cloak @click.outside="modeMenuOpen = false"
                     class="absolute right-0 z-30 mt-1 w-44 bg-white border border-gray-200 rounded-lg shadow-lg py-1 text-xs">
                    <button type="button" @click="setMode('comment')"
                            :class="mode === 'comment' ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-600'"
                            class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">💬 Comment</button>
                    @if ($isOwner && ! $document->legal_hold)
                        <button type="button" @click="setMode('edit')"
                                :class="mode === 'edit' ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-600'"
                                class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">✏️ Edit</button>
                    @endif
                    @if ($document->claims->isNotEmpty() || $document->referenceAttachments->isNotEmpty())
                        <button type="button" @click="setMode('reference')"
                                :class="mode === 'reference' ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-600'"
                                class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">🔗 Reference</button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div x-show="loading" class="text-sm text-gray-500 py-8 text-center">Loading preview…</div>
    <div x-show="error" x-cloak x-text="error" class="text-sm text-amber-700 bg-amber-50 rounded-md p-3"></div>

    <p class="text-xs text-gray-500 mb-2" x-show="!loading && !error && mode === 'comment'" x-cloak>Click anywhere on the page to drop a comment pin.</p>
    <p class="text-xs text-indigo-700 mb-2" x-show="!loading && !error && mode === 'reference'" x-cloak>Drag to select the exact claim text, then link it to a claim and/or reference.</p>
    <p class="text-xs text-amber-700 mb-2" x-show="!loading && !error && mode === 'edit' && editTool === 'add_text'" x-cloak>Click anywhere on the page to place text.</p>
    <p class="text-xs text-red-700 mb-2" x-show="!loading && !error && mode === 'edit' && editTool === 'redact'" x-cloak>Drag a box over the content you want covered/removed.</p>
    <p class="text-xs text-gray-500 mb-2" x-show="!loading && !error && mode === 'edit' && !editTool" x-cloak>Choose Add Text or Redact below, then click or drag on the page.</p>

    <div class="flex items-center gap-2 mb-2" x-show="!loading && !error && mode === 'edit'" x-cloak>
        <button type="button" @click="setEditTool('add_text')"
                :class="editTool === 'add_text' ? 'bg-amber-500 text-white border-amber-500' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                class="px-3 py-1.5 border rounded-md text-xs font-medium">✏️ Add Text</button>
        <button type="button" @click="setEditTool('redact')"
                :class="editTool === 'redact' ? 'bg-red-700 text-white border-red-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                class="px-3 py-1.5 border rounded-md text-xs font-medium">⬛ Redact</button>
    </div>

    {{--
        Two nested wrappers, not one: the OUTER div is the scroll/clip viewport
        (overflow-auto + max-height, only ever 75vh tall on screen). The INNER div is
        the actual `position:relative` containing block every `left:X%; top:Y%` pin/
        composer below resolves against, sized to the canvas's full natural
        dimensions (inline-block, uncapped). Collapsing these into one div made every
        percentage resolve against the *clipped* 75vh box instead of the full page -
        on any PDF tall/zoomed enough to need scrolling, a click's page-relative %
        (correct) and the pin's rendered position (resolved against the wrong,
        shorter box) came apart, landing pins nowhere near the click.
    --}}
    <div x-show="!loading && !error" x-cloak class="border border-gray-200 rounded overflow-auto max-w-full bg-gray-50" style="max-height: 75vh;">
        <div class="relative inline-block">
        <canvas x-ref="canvas"
                @click="onCanvasClick($event)"
                @mousedown="onCanvasMouseDown($event)"
                @mousemove="onCanvasMouseMove($event)"
                @mouseup="onCanvasMouseUp($event)"
                :class="mode === 'edit' ? (editTool === 'redact' ? 'cursor-crosshair' : (editTool === 'add_text' ? 'cursor-text' : 'cursor-default')) : (mode === 'comment' ? 'cursor-crosshair' : 'cursor-default')"
                class="block"></canvas>

        <!-- REQ-3.3: invisible, selectable text layer overlaying the canvas -->
        <div x-ref="textLayer" class="pdf-text-layer" :class="mode === 'reference' ? 'mapping-active' : ''" @mouseup="onTextSelected()"></div>

        <template x-for="pin in currentAnnotations()" :key="pin.id">
            <div class="absolute -translate-x-1/2 -translate-y-1/2" :style="`left:${pin.x_position}%; top:${pin.y_position}%;`">
                <button type="button" @click.stop="openPin = (openPin === pin.id ? null : pin.id)"
                        class="pin w-6 h-6 rounded-full bg-accent-500 text-white text-xs font-bold flex items-center justify-center shadow ring-2 ring-white hover:bg-accent-600">
                    !
                </button>
                <div x-show="openPin === pin.id" @click.outside="openPin = null" x-cloak
                     class="absolute z-20 top-7 left-0 w-64 bg-white border border-gray-200 rounded-lg shadow-lg p-3 text-xs">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-medium text-gray-900" x-text="pin.author.name"></span>
                        <span class="text-gray-400" x-text="pin.created_at"></span>
                    </div>
                    <p class="text-gray-700 mb-2 whitespace-pre-line" x-text="pin.body"></p>

                    <template x-for="reply in pin.replies" :key="reply.id">
                        <div class="ml-2 pl-2 border-l-2 border-gray-100 mb-1">
                            <div>
                                <span class="font-medium text-gray-800" x-text="reply.author.name"></span>
                                <span class="text-gray-400" x-text="'· ' + reply.created_at"></span>
                            </div>
                            <p class="text-gray-600 whitespace-pre-line" x-text="reply.body"></p>
                        </div>
                    </template>

                    <div class="mt-2 flex gap-1">
                        <input type="text" x-model="replyBody" placeholder="Reply…" @keydown.enter="submitReply(pin)"
                               class="flex-1 text-xs border-gray-300 rounded">
                        <button type="button" @click="submitReply(pin)" :disabled="posting" class="text-brand-600 font-medium disabled:opacity-40">Send</button>
                    </div>
                </div>
            </div>
        </template>

        <div x-show="pendingPin" x-cloak
             class="absolute z-20 w-64 bg-white border border-gray-200 rounded-lg shadow-lg p-3"
             :style="pendingPin ? `left:${pendingPin.x}%; top:${pendingPin.y}%;` : ''">
            <textarea x-model="newBody" rows="2" placeholder="Add a comment at this spot…" class="w-full text-xs border-gray-300 rounded mb-2"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="cancelPending()" class="text-xs text-gray-500">Cancel</button>
                <button type="button" @click="submitPin()" :disabled="posting" class="text-xs text-white bg-brand-600 px-2 py-1 rounded disabled:opacity-40">Post</button>
            </div>
        </div>

        <!-- REQ-3.3: claim <-> reference mapping pins -->
        <template x-for="mapping in currentMappings()" :key="mapping.id">
            <div class="absolute -translate-x-1/2 -translate-y-1/2" :style="`left:${mapping.x_position}%; top:${mapping.y_position}%;`">
                <button type="button" @click.stop="openMapping = (openMapping === mapping.id ? null : mapping.id)"
                        class="pin w-6 h-6 rounded-full bg-indigo-600 text-white text-xs font-bold flex items-center justify-center shadow ring-2 ring-white hover:bg-indigo-700">
                    🔗
                </button>
                <div x-show="openMapping === mapping.id" @click.outside="openMapping = null" x-cloak
                     class="absolute z-20 top-7 left-0 w-72 bg-white border border-gray-200 rounded-lg shadow-lg p-3 text-xs space-y-1.5">
                    <p class="text-gray-700 italic border-l-2 border-indigo-200 pl-2" x-show="mapping.selected_text" x-text="'“' + mapping.selected_text + '”'"></p>
                    <p x-show="mapping.claim"><span class="text-gray-400">Claim:</span> <span class="text-gray-800" x-text="mapping.claim?.match_text"></span></p>
                    <p x-show="mapping.reference">
                        <span class="text-gray-400">Reference:</span>
                        <a :href="mapping.reference?.download_url" target="_blank" class="text-brand-600 hover:underline" x-text="mapping.reference?.title"></a>
                    </p>
                    <div class="flex items-center justify-between pt-1.5 border-t border-gray-100">
                        <span class="text-gray-400" x-text="'Pinned by ' + mapping.creator.name"></span>
                        <button type="button" @click="deleteMapping(mapping)" class="text-red-500 hover:underline">Remove</button>
                    </div>
                </div>
            </div>
        </template>

        <div x-show="pendingMapping" x-cloak
             class="absolute z-20 w-72 bg-white border border-indigo-200 rounded-lg shadow-lg p-3"
             :style="pendingMapping ? `left:${pendingMapping.x}%; top:${pendingMapping.y}%;` : ''">
            <p class="text-xs text-gray-700 italic border-l-2 border-indigo-200 pl-2 mb-2" x-text="pendingMapping ? '“' + pendingMapping.text + '”' : ''"></p>
            <select x-model="mappingClaimId" class="w-full text-xs border-gray-300 rounded mb-1.5">
                <option value="">— Link to a claim (optional) —</option>
                <template x-for="c in claims" :key="c.id">
                    <option :value="c.id" x-text="c.match_text"></option>
                </template>
            </select>
            <select x-model="mappingReferenceId" class="w-full text-xs border-gray-300 rounded mb-2">
                <option value="">— Link to a reference file (optional) —</option>
                <template x-for="r in references" :key="r.id">
                    <option :value="r.id" x-text="r.title"></option>
                </template>
            </select>
            <div class="flex justify-end gap-2">
                <button type="button" @click="cancelMapping()" class="text-xs text-gray-500">Cancel</button>
                <button type="button" @click="submitMapping()" :disabled="posting" class="text-xs text-white bg-indigo-600 px-2 py-1 rounded disabled:opacity-40">Save Link</button>
            </div>
        </div>

        <!-- content edits (mode: 'edit'), folded in from the former standalone PDF editor page -->

        <!-- live redact drag preview -->
        <div x-show="mode === 'edit' && dragStart && dragCurrent" x-cloak
             class="absolute border-2 border-red-700 bg-red-700/30 pointer-events-none"
             :style="dragRectStyle()"></div>

        <!-- committed pending content edits on the current page -->
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

        <!-- add-text composer -->
        <div x-show="textComposer" x-cloak
             class="absolute z-20 w-64 bg-white border border-amber-300 rounded-lg shadow-lg p-3"
             :style="textComposer ? `left:${textComposer.x}%; top:${textComposer.y}%;` : ''">
            <textarea x-model="textValue" rows="2" placeholder="Text to add…" class="w-full text-xs border-gray-300 rounded mb-2" autofocus></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="cancelEditComposer()" class="text-xs text-gray-500">Cancel</button>
                <button type="button" @click="submitTextEdit()" class="text-xs text-white bg-amber-600 px-2 py-1 rounded">Place</button>
            </div>
        </div>
        </div>
    </div>

    <div x-show="!loading && !error && mode === 'edit'" x-cloak class="mt-3 border-t border-gray-100 pt-3">
        <h4 class="text-xs font-semibold text-gray-900 mb-2">Pending Content Edits (<span x-text="pendingEdits.length"></span>)</h4>
        <div x-show="saveError" x-cloak x-text="saveError" class="bg-red-50 border border-red-200 text-red-800 text-xs rounded-md p-2 mb-2"></div>
        <p x-show="pendingEdits.length === 0" class="text-xs text-gray-500 mb-2">Use Add Text or Redact above, then edits will appear here before you save.</p>
        <div class="space-y-1.5 max-h-40 overflow-y-auto mb-2" x-show="pendingEdits.length > 0">
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
        <div class="flex items-center gap-2">
            <input type="text" x-model="changeNotes" placeholder="Change notes (optional)…" class="flex-1 text-xs border-gray-300 rounded-md">
            <button type="button" @click="saveEdits()" :disabled="pendingEdits.length === 0 || saving"
                    class="text-xs text-white bg-brand-600 px-3 py-1.5 rounded-md hover:bg-brand-700 disabled:opacity-40 whitespace-nowrap">
                <span x-show="!saving">Save as New Version</span>
                <span x-show="saving" x-cloak>Saving…</span>
            </button>
        </div>
    </div>
</div>
