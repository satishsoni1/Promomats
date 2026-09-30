@php
    $version = $document->currentVersion;
    $viewerIsImage = $version->isImage();
@endphp
{{--
    Review viewer for PDFs and image artwork (resources/js/pdf-viewer.js). Adobe-style:
    the page carries only small numbered markers and translucent highlights; every
    comment thread lives in the panel on the right, so nothing sits on top of the
    artwork. Opens in "fit page" so the whole creative is visible at once.
--}}
<div x-ref="viewerRoot"
     class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4"
     :class="fullscreen ? '!rounded-none overflow-auto' : ''"
     x-data="pdfAnnotationViewer({
         pdfUrl: '{{ $version->viewUrl() }}',
         isImage: {{ $viewerIsImage ? 'true' : 'false' }},
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
         resolveUrlTemplate: '{{ route('documents.comments.resolve', [$document, '__ID__']) }}',
         commentUpdateUrlTemplate: '{{ route('documents.comments.update', [$document, '__ID__']) }}',
     })"
     x-init="init()">

    {{-- ---------- toolbar ---------- --}}
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 text-sm">
        <h3 class="text-lg font-semibold text-gray-900">Review &amp; Annotate</h3>
        <div class="flex flex-wrap items-center gap-2" x-show="!loading && !error" x-cloak>
            <div class="flex items-center gap-1" x-show="numPages > 1">
                <button type="button" @click="prevPage()" :disabled="pageNum <= 1" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40">‹</button>
                <span class="text-gray-600 text-xs whitespace-nowrap">Page <span x-text="pageNum"></span> / <span x-text="numPages"></span></span>
                <button type="button" @click="nextPage()" :disabled="pageNum >= numPages" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40">›</button>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" @click="zoomOut()" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50" title="Zoom out">−</button>
                <span class="text-xs text-gray-500 w-11 text-center" x-text="zoomLabel()"></span>
                <button type="button" @click="zoomIn()" class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50" title="Zoom in">+</button>
            </div>
            <div class="flex items-center rounded border border-gray-200 overflow-hidden text-xs">
                <button type="button" @click="setFit('page')" :class="fit === 'page' ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-50'" class="px-2 py-1" title="Show the whole page">Full view</button>
                <button type="button" @click="setFit('width')" :class="fit === 'width' ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-600 hover:bg-gray-50'" class="px-2 py-1 border-l border-gray-200">Fit width</button>
            </div>
            <button type="button" @click="toggleFullscreen()" class="px-2 py-1 border border-gray-200 rounded text-xs hover:bg-gray-50" x-text="fullscreen ? 'Exit fullscreen' : '⛶ Fullscreen'"></button>
            @unless ($viewerIsImage)
                <button type="button" @click="downloadWithComments()" :disabled="exporting"
                        title="Download this PDF with every review comment embedded as real PDF notes/highlights - opens with its comments in Acrobat, Edge, Preview, etc."
                        class="px-2 py-1 border border-gray-200 rounded text-xs whitespace-nowrap hover:bg-gray-50 disabled:opacity-40">
                    <span x-show="!exporting">⬇ With comments</span>
                    <span x-show="exporting" x-cloak>Preparing…</span>
                </button>
            @endunless
            @if (! $viewerIsImage && (($isOwner && ! $document->legal_hold) || $document->claims->isNotEmpty() || $document->referenceAttachments->isNotEmpty()))
                <div class="relative">
                    <button type="button" @click="modeMenuOpen = !modeMenuOpen"
                            class="px-2 py-1 border border-gray-200 rounded text-xs whitespace-nowrap flex items-center gap-1 hover:bg-gray-50">
                        <span x-text="mode === 'edit' ? '✏️ Edit' : (mode === 'reference' ? '🔗 Reference' : '💬 Comment')"></span>
                        <span class="text-gray-400">▾</span>
                    </button>
                    <div x-show="modeMenuOpen" x-cloak @click.outside="modeMenuOpen = false"
                         class="absolute right-0 z-30 mt-1 w-44 bg-white border border-gray-200 rounded-lg shadow-lg py-1 text-xs">
                        <button type="button" @click="setMode('comment')" :class="mode === 'comment' ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-600'" class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">💬 Comment</button>
                        @if ($isOwner && ! $document->legal_hold)
                            <button type="button" @click="setMode('edit')" :class="mode === 'edit' ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-600'" class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">✏️ Edit</button>
                        @endif
                        @if ($document->claims->isNotEmpty() || $document->referenceAttachments->isNotEmpty())
                            <button type="button" @click="setMode('reference')" :class="mode === 'reference' ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-600'" class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">🔗 Reference</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div x-show="loading" class="text-sm text-gray-500 py-8 text-center">Loading preview…</div>
    <div x-show="error" x-cloak x-text="error" class="text-sm text-amber-700 bg-amber-50 rounded-md p-3"></div>
    <div x-show="exportError" x-cloak x-text="exportError" class="text-xs text-red-800 bg-red-50 border border-red-200 rounded-md p-2 mb-2"></div>

    {{-- ---------- tool rows ---------- --}}
    <div class="flex flex-wrap items-center gap-2 mb-2" x-show="!loading && !error && mode === 'comment'" x-cloak>
        <button type="button" @click="setCommentTool('pin')"
                :class="commentTool === 'pin' ? 'bg-accent-500 text-white border-accent-500' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                class="px-3 py-1.5 border rounded-md text-xs font-medium">📍 Pin</button>
        @unless ($viewerIsImage)
            <button type="button" @click="setCommentTool('highlight')"
                    :class="commentTool === 'highlight' ? 'bg-yellow-400 text-gray-900 border-yellow-400' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1.5 border rounded-md text-xs font-medium">🖍 Highlight text</button>
        @endunless
        <button type="button" @click="setCommentTool('area')"
                :class="commentTool === 'area' ? 'bg-sky-500 text-white border-sky-500' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                class="px-3 py-1.5 border rounded-md text-xs font-medium">▭ Highlight area</button>
        <p class="text-xs text-gray-500" x-show="commentTool === 'pin'">Click a spot on the page to comment on it.</p>
        <p class="text-xs text-gray-500" x-show="commentTool === 'highlight'">Drag across the words to comment on them or suggest replacement text.</p>
        <p class="text-xs text-gray-500" x-show="commentTool === 'area'">Drag a box around any part of the creative — images, logos, layout.</p>
    </div>
    <p class="text-xs text-indigo-700 mb-2" x-show="!loading && !error && mode === 'reference'" x-cloak>Drag to select the exact claim text, then link it to a claim and/or reference.</p>
    <div class="flex items-center gap-2 mb-2" x-show="!loading && !error && mode === 'edit'" x-cloak>
        <button type="button" @click="setEditTool('add_text')"
                :class="editTool === 'add_text' ? 'bg-amber-500 text-white border-amber-500' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                class="px-3 py-1.5 border rounded-md text-xs font-medium">✏️ Add Text</button>
        <button type="button" @click="setEditTool('redact')"
                :class="editTool === 'redact' ? 'bg-red-700 text-white border-red-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                class="px-3 py-1.5 border rounded-md text-xs font-medium">⬛ Redact</button>
        <p class="text-xs text-gray-500" x-text="editTool === 'redact' ? 'Drag a box over the content to cover.' : (editTool === 'add_text' ? 'Click where the text should go.' : 'Choose Add Text or Redact.')"></p>
    </div>

    {{-- ---------- page + comment panel ---------- --}}
    <div x-show="!loading && !error" x-cloak class="flex flex-col lg:flex-row gap-3">

        {{--
            Two nested wrappers: the OUTER div is the scroll viewport; the INNER div is
            the position:relative containing block every left:X%/top:Y% overlay resolves
            against, sized to the canvas's full dimensions (see the git history of this
            file for why collapsing them misplaces pins on scrolled pages).
        --}}
        <div x-ref="viewport" class="flex-1 min-w-0 border border-gray-200 rounded overflow-auto bg-gray-100 text-center"
             :style="fullscreen ? 'height: calc(100vh - 150px)' : 'height: 75vh'">
            <div class="relative inline-block align-top m-2 shadow-sm bg-white text-left">
                <canvas x-ref="canvas"
                        @click="onCanvasClick($event)"
                        @mousedown="onCanvasMouseDown($event)"
                        @mousemove="onCanvasMouseMove($event)"
                        @mouseup="onCanvasMouseUp($event)"
                        :class="mode === 'edit' ? (editTool === 'redact' ? 'cursor-crosshair' : (editTool === 'add_text' ? 'cursor-text' : 'cursor-default')) : (mode === 'comment' ? 'cursor-crosshair' : 'cursor-default')"
                        class="block select-none"></canvas>

                {{-- highlights: translucent, never catching clicks, never hiding the artwork --}}
                <template x-for="pin in currentAnnotations().filter((p) => p.highlight_rects?.length)" :key="'hl-' + pin.id">
                    <div>
                        <template x-for="(r, i) in pin.highlight_rects" :key="i">
                            <div class="absolute pointer-events-none"
                                 :class="pin.selected_text
                                    ? (pin.resolved ? 'bg-gray-300/40 mix-blend-multiply' : (selectedId === pin.id ? 'bg-yellow-300/60 mix-blend-multiply' : 'bg-yellow-200/45 mix-blend-multiply'))
                                    : (pin.resolved ? 'border border-dashed border-gray-400' : (selectedId === pin.id ? 'border-2 border-sky-500 bg-sky-400/10' : 'border-2 border-sky-400/80'))"
                                 :style="`left:${r.x}%; top:${r.y}%; width:${r.w}%; height:${r.h}%;`"></div>
                        </template>
                    </div>
                </template>
                <template x-if="composer?.rects">
                    <div>
                        <template x-for="(r, i) in composer.rects" :key="i">
                            <div class="absolute pointer-events-none"
                                 :class="composer.kind === 'highlight' ? 'bg-yellow-300/60 mix-blend-multiply' : 'border-2 border-sky-500 bg-sky-400/10'"
                                 :style="`left:${r.x}%; top:${r.y}%; width:${r.w}%; height:${r.h}%;`"></div>
                        </template>
                    </div>
                </template>

                <div x-ref="textLayer" class="pdf-text-layer" :class="textSelectable() ? 'mapping-active' : ''" @mouseup="onTextSelected()"></div>

                {{-- small numbered markers; the thread opens in the side panel --}}
                <template x-for="pin in currentAnnotations()" :key="pin.id">
                    <button type="button" @click.stop="selectComment({ ...pin, page: pageNum })"
                            class="pin absolute -translate-x-1/2 -translate-y-1/2 w-5 h-5 rounded-full text-white text-[10px] font-bold flex items-center justify-center shadow ring-2 ring-white transition"
                            :class="[
                                pin.resolved ? 'bg-gray-400' : (pin.highlight_rects?.length ? (pin.selected_text ? 'bg-yellow-500' : 'bg-sky-500') : 'bg-accent-500'),
                                selectedId === pin.id ? 'scale-125 ring-brand-300' : 'opacity-90 hover:opacity-100',
                            ]"
                            :style="`left:${pin.x_position}%; top:${pin.y_position}%;`"
                            :title="pin.author.name + ': ' + pin.body"
                            x-text="markerNo(pin.id)"></button>
                </template>
                <div x-show="composer && composer.kind === 'pin'" x-cloak
                     class="absolute -translate-x-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-brand-600 ring-2 ring-white shadow animate-pulse pointer-events-none"
                     :style="composer ? `left:${composer.x}%; top:${composer.y}%;` : ''"></div>

                {{-- live drag box (area comment / redact) --}}
                <div x-show="dragStart && dragCurrent" x-cloak class="absolute pointer-events-none"
                     :class="mode === 'edit' ? 'border-2 border-red-700 bg-red-700/30' : 'border-2 border-sky-500 bg-sky-400/10'"
                     :style="dragRectStyle()"></div>

                {{-- REQ-3.3: claim <-> reference mapping pins --}}
                <template x-for="mapping in currentMappings()" :key="mapping.id">
                    <div class="absolute -translate-x-1/2 -translate-y-1/2" :style="`left:${mapping.x_position}%; top:${mapping.y_position}%;`">
                        <button type="button" @click.stop="openMapping = (openMapping === mapping.id ? null : mapping.id)"
                                class="pin w-5 h-5 rounded-full bg-indigo-600 text-white text-[10px] flex items-center justify-center shadow ring-2 ring-white hover:bg-indigo-700">🔗</button>
                        <div x-show="openMapping === mapping.id" @click.outside="openMapping = null" x-cloak
                             class="absolute z-20 top-6 left-0 w-72 bg-white border border-gray-200 rounded-lg shadow-lg p-3 text-xs space-y-1.5">
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
                        <template x-for="c in claims" :key="c.id"><option :value="c.id" x-text="c.match_text"></option></template>
                    </select>
                    <select x-model="mappingReferenceId" class="w-full text-xs border-gray-300 rounded mb-2">
                        <option value="">— Link to a reference file (optional) —</option>
                        <template x-for="r in references" :key="r.id"><option :value="r.id" x-text="r.title"></option></template>
                    </select>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="cancelMapping()" class="text-xs text-gray-500">Cancel</button>
                        <button type="button" @click="submitMapping()" :disabled="posting" class="text-xs text-white bg-indigo-600 px-2 py-1 rounded disabled:opacity-40">Save Link</button>
                    </div>
                </div>

                {{-- content edits (mode: 'edit') --}}
                <template x-for="edit in editsForPage()" :key="edit._id">
                    <div class="absolute pointer-events-none"
                         :style="edit.edit_type === 'redact'
                            ? `left:${edit.x}%; top:${edit.y}%; width:${edit.width}%; height:${edit.height}%; background: rgba(140,20,20,0.55); border: 2px solid rgba(90,10,10,0.8);`
                            : `left:${edit.x}%; top:${edit.y}%;`">
                        <span x-show="edit.edit_type === 'add_text'" x-cloak
                              class="inline-block bg-amber-200 border border-amber-500 text-amber-900 text-xs px-1 rounded -translate-y-full whitespace-nowrap" x-text="edit.content"></span>
                    </div>
                </template>
                <div x-show="textComposer" x-cloak
                     class="absolute z-20 w-64 bg-white border border-amber-300 rounded-lg shadow-lg p-3"
                     :style="textComposer ? `left:${textComposer.x}%; top:${textComposer.y}%;` : ''">
                    <textarea x-model="textValue" rows="2" placeholder="Text to add…" class="w-full text-xs border-gray-300 rounded mb-2"></textarea>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="cancelEditComposer()" class="text-xs text-gray-500">Cancel</button>
                        <button type="button" @click="submitTextEdit()" class="text-xs text-white bg-amber-600 px-2 py-1 rounded">Place</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ---------- comment panel ---------- --}}
        <aside x-show="mode === 'comment'" class="w-full lg:w-80 shrink-0 flex flex-col border border-gray-200 rounded bg-white"
               :style="fullscreen ? 'height: calc(100vh - 150px)' : 'max-height: 75vh'">
            <div class="px-3 py-2 border-b border-gray-100 space-y-1.5">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-gray-900">Comments <span class="font-normal text-gray-500">(<span x-text="openCount()"></span> open)</span></h4>
                    <label class="flex items-center gap-1 text-[11px] text-gray-500"><input type="checkbox" x-model="showResolved" class="rounded border-gray-300 text-brand-600 w-3 h-3"> resolved</label>
                </div>
                <div class="flex text-[11px] rounded border border-gray-200 overflow-hidden w-fit" x-show="numPages > 1">
                    <button type="button" @click="panelScope = 'page'" :class="panelScope === 'page' ? 'bg-gray-100 font-medium' : 'text-gray-500'" class="px-2 py-0.5">This page</button>
                    <button type="button" @click="panelScope = 'all'" :class="panelScope === 'all' ? 'bg-gray-100 font-medium' : 'text-gray-500'" class="px-2 py-0.5 border-l border-gray-200">All pages</button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-2 space-y-2">
                {{-- new comment --}}
                <div x-show="composer" x-cloak class="border-2 border-brand-300 rounded-lg p-2.5 bg-brand-50/40 space-y-2">
                    <p class="text-[11px] font-medium text-brand-700" x-text="composer?.kind === 'highlight' ? 'Comment on highlighted text' : (composer?.kind === 'area' ? 'Comment on marked area' : 'Comment on this spot')"></p>
                    <p x-show="composer?.text" class="text-xs text-gray-600 italic border-l-2 border-yellow-400 pl-2 line-clamp-4" x-text="'“' + (composer?.text || '') + '”'"></p>
                    <textarea x-ref="composerBody" x-model="newBody" rows="3" placeholder="Your comment…" class="w-full text-xs border-gray-300 rounded"></textarea>
                    <div x-show="composer?.rects">
                        <label class="text-[11px] text-gray-500">Replace with (optional)</label>
                        <textarea x-model="newReplacement" rows="2" placeholder="Suggested wording for the designer…" class="w-full text-xs border-emerald-300 rounded bg-emerald-50/40"></textarea>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="cancelComposer()" class="text-xs text-gray-500">Cancel</button>
                        <button type="button" @click="submitComposer()" :disabled="posting || !newBody.trim()" class="text-xs text-white bg-brand-600 px-2.5 py-1 rounded disabled:opacity-40">Post</button>
                    </div>
                </div>

                <p x-show="!composer && panelItems().length === 0" class="text-xs text-gray-400 text-center py-6">No comments <span x-text="panelScope === 'page' && numPages > 1 ? 'on this page' : 'yet'"></span>. Use Pin, Highlight text or Highlight area to add one.</p>

                <template x-for="item in panelItems()" :key="'card-' + item.id">
                    <div :id="'comment-card-' + item.id"
                         @click="selectedId !== item.id && selectComment(item)"
                         :class="selectedId === item.id ? 'border-brand-300 ring-1 ring-brand-200' : 'border-gray-200 hover:border-gray-300 cursor-pointer'"
                         class="border rounded-lg p-2.5 text-xs bg-white">
                        <div class="flex items-start gap-2">
                            <span class="shrink-0 w-5 h-5 rounded-full text-white text-[10px] font-bold flex items-center justify-center"
                                  :class="item.resolved ? 'bg-gray-400' : (item.highlight_rects?.length ? (item.selected_text ? 'bg-yellow-500' : 'bg-sky-500') : 'bg-accent-500')"
                                  x-text="markerNo(item.id)"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-1">
                                    <span class="font-medium text-gray-900 truncate" x-text="item.author.name"></span>
                                    <span class="text-gray-400 shrink-0" x-text="(panelScope === 'all' ? 'p.' + item.page + ' · ' : '') + item.created_at"></span>
                                </div>
                                <p x-show="item.from_version" class="text-[10px] text-amber-700" x-text="'Made on v' + item.from_version + ' — position may have shifted'"></p>
                            </div>
                        </div>

                        <p x-show="item.selected_text" class="mt-1.5 text-gray-600 italic border-l-2 border-yellow-300 pl-2" :class="selectedId === item.id ? '' : 'line-clamp-2'" x-text="'“' + item.selected_text + '”'"></p>
                        <div x-show="item.replacement_text && editingId !== item.id" class="mt-1.5 rounded bg-emerald-50 border border-emerald-200 px-2 py-1 text-emerald-900">
                            <span class="text-[10px] uppercase tracking-wide text-emerald-700">Replace with</span>
                            <p class="whitespace-pre-line" x-text="item.replacement_text"></p>
                        </div>

                        <template x-if="editingId !== item.id">
                            <p class="mt-1.5 text-gray-800 whitespace-pre-line" :class="[selectedId === item.id ? '' : 'line-clamp-3', item.resolved ? 'text-gray-400 line-through' : '']">
                                <span x-text="item.body"></span>
                                <span x-show="item.edited" class="text-[10px] text-gray-400 no-underline">(edited)</span>
                            </p>
                        </template>
                        <template x-if="editingId === item.id">
                            <div class="mt-1.5 space-y-1.5" @click.stop>
                                <textarea x-model="editBody" rows="3" class="w-full text-xs border-gray-300 rounded"></textarea>
                                <textarea x-show="item.highlight_rects?.length" x-model="editReplacement" rows="2" placeholder="Replace with (optional)" class="w-full text-xs border-emerald-300 rounded bg-emerald-50/40"></textarea>
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="editingId = null" class="text-gray-500">Cancel</button>
                                    <button type="button" @click="saveEdit(findPin(item.id))" :disabled="posting" class="text-white bg-brand-600 px-2 py-0.5 rounded disabled:opacity-40">Save</button>
                                </div>
                            </div>
                        </template>

                        <div x-show="selectedId === item.id" class="mt-2 space-y-1.5" @click.stop>
                            <template x-for="reply in findPin(item.id)?.replies || []" :key="reply.id">
                                <div class="ml-2 pl-2 border-l-2 border-gray-100">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium text-gray-800" x-text="reply.author.name"></span>
                                        <span class="text-gray-400" x-text="reply.created_at"></span>
                                    </div>
                                    <template x-if="editingId !== reply.id">
                                        <p class="text-gray-600 whitespace-pre-line"><span x-text="reply.body"></span> <span x-show="reply.edited" class="text-[10px] text-gray-400">(edited)</span>
                                            <button type="button" x-show="reply.can_edit" @click="startEdit(reply)" class="text-[10px] text-brand-600 hover:underline ml-1">Edit</button></p>
                                    </template>
                                    <template x-if="editingId === reply.id">
                                        <div class="space-y-1">
                                            <textarea x-model="editBody" rows="2" class="w-full text-xs border-gray-300 rounded"></textarea>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" @click="editingId = null" class="text-gray-500">Cancel</button>
                                                <button type="button" @click="saveEdit(reply)" :disabled="posting" class="text-white bg-brand-600 px-2 py-0.5 rounded">Save</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div class="flex gap-1">
                                <input type="text" x-model="replyBody" placeholder="Reply…" @keydown.enter="submitReply(findPin(item.id))" class="flex-1 text-xs border-gray-300 rounded py-1">
                                <button type="button" @click="submitReply(findPin(item.id))" :disabled="posting" class="text-brand-600 font-medium disabled:opacity-40">Send</button>
                            </div>
                            <div class="flex items-center justify-between pt-1.5 border-t border-gray-100">
                                <button type="button" x-show="item.can_edit" @click="startEdit(findPin(item.id))" class="text-brand-600 hover:underline">Edit</button>
                                <span x-show="!item.can_edit"></span>
                                <span x-show="item.resolved" class="text-green-700" x-text="'✓ Resolved' + (item.resolved_by ? ' by ' + item.resolved_by : '')"></span>
                                <button type="button" x-show="item.can_resolve" @click="toggleResolved(item.id)" :disabled="posting"
                                        :class="item.resolved ? 'text-gray-500' : 'text-green-700'" class="font-medium hover:underline disabled:opacity-40"
                                        x-text="item.resolved ? 'Reopen' : '✓ Resolve'"></button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </aside>
    </div>

    {{-- ---------- pending content edits (mode: 'edit') ---------- --}}
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
