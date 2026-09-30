import * as pdfjsLib from 'pdfjs-dist';
import { PDFDocument, PDFHexString, PDFString, rgb, StandardFonts } from 'pdf-lib';

// pdf.js needs a worker script; Vite bundles it as its own asset and hands us the URL.
pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.mjs', import.meta.url).href;

/**
 * Alpine.js component powering the in-page review viewer for PDFs and image
 * artwork (JPG/PNG/GIF/WebP). Reviewing works like Adobe's: the page shows only
 * small numbered markers and translucent highlights, while every comment thread
 * lives in a panel beside the page - nothing is drawn over the artwork itself.
 *
 * One `mode` drives what a click/drag on the page does:
 *   - 'comment'   sub-tools: 'pin' (click a spot), 'highlight' (select text,
 *                 PDFs with a text layer) or 'area' (drag a box - works on any
 *                 artwork, including images and scanned PDFs). A highlight/area
 *                 comment can propose replacement text for the selection.
 *   - 'reference' (REQ-3.3, PDFs) select text to pin a claim/reference pair
 *   - 'edit'      (PDFs, owner) real in-place content edits (add text / redact)
 *
 * Opens in "fit page" so the whole creative is visible at once (UAT feedback),
 * with fit-width, zoom and fullscreen.
 */
export default function pdfAnnotationViewer({
    pdfUrl, isImage = false, csrfToken, storeUrl, initialAnnotations,
    mappingStoreUrl, mappingDestroyUrlBase, documentClaims, documentReferences, initialMappings,
    pdfEditStoreUrl, originalFilename, resolveUrlTemplate, commentUpdateUrlTemplate,
}) {
    // Deliberately kept OUTSIDE the returned Alpine data object, in closure scope: Alpine
    // makes every property on x-data reactive by wrapping it in a Proxy, and pdf.js's
    // PDFDocumentProxy/PDFPageProxy/TextLayer (v6+) use private class fields (#field)
    // internally. Calling a method/getter that touches a private field *through* a Proxy
    // throws "Cannot read from private field", because the private field is tied to the
    // real object's identity, not a proxy wrapping it. Keeping these here means Alpine
    // never sees them and never wraps them.
    let pdfDoc = null;
    let imageEl = null;
    let textLayerTask = null;
    let renderTask = null;
    let renderGeneration = 0; // see renderPage() - a simple "cancel the previous task" isn't enough on its own
    let originalBytes = null; // raw PDF bytes, fetched lazily for pdf-lib (edit save / export)
    let fetchingOriginalBytes = null;
    let editIdSeq = 0;

    return {
        isImage,
        pageNum: 1,
        numPages: 0,
        scale: 1.2,
        fit: 'page', // 'page' | 'width' | null (manual zoom)
        fullscreen: false,
        loading: true,
        error: null,
        annotations: initialAnnotations || {},
        posting: false,

        // Comment state. `selectedId` is the thread open in the side panel;
        // `composer` is a new comment being written (anchored where it was placed).
        commentTool: 'pin', // 'pin' | 'highlight' | 'area'
        selectedId: null,
        composer: null, // { kind, x, y, rects?, text? }
        newBody: '',
        newReplacement: '',
        replyBody: '',
        editingId: null,
        editBody: '',
        editReplacement: '',
        showResolved: false,
        panelScope: 'page', // 'page' | 'all'
        exporting: false,
        exportError: null,

        mode: 'comment', // 'comment' | 'reference' | 'edit'
        modeMenuOpen: false,

        // REQ-3.3: claim <-> reference mapping state.
        mappings: initialMappings || {},
        pendingMapping: null,
        mappingClaimId: '',
        mappingReferenceId: '',
        openMapping: null,
        claims: documentClaims || [],
        references: documentReferences || [],

        // Edit mode state.
        editTool: null, // null | 'add_text' | 'redact'
        pendingEdits: [],
        textComposer: null,
        textValue: '',
        dragStart: null,
        dragCurrent: null,
        changeNotes: '',
        saving: false,
        saveError: null,

        async init() {
            try {
                if (isImage) {
                    imageEl = await new Promise((resolve, reject) => {
                        const img = new Image();
                        img.onload = () => resolve(img);
                        img.onerror = () => reject(new Error('image could not be loaded'));
                        img.src = pdfUrl;
                    });
                    this.numPages = 1;
                } else {
                    // withCredentials: this URL sits behind Laravel's auth middleware, and
                    // pdf.js's internal fetch doesn't send the session cookie unless told to.
                    pdfDoc = await pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true }).promise;
                    this.numPages = pdfDoc.numPages;
                }
                // The viewport only has a size once it's shown.
                this.loading = false;
                await this.$nextTick();
                await this.renderPage();
            } catch (e) {
                this.error = 'Could not load this file for preview (' + (e?.message || 'unknown error') + '). You can still download it from the Versions list.';
                console.error(e);
            } finally {
                this.loading = false;
            }

            const onResize = () => { if (this.fit) this.renderPage(); };
            window.addEventListener('resize', onResize);
            document.addEventListener('fullscreenchange', () => {
                this.fullscreen = document.fullscreenElement === this.$refs.viewerRoot;
                this.fit = 'page';
                this.$nextTick(() => this.renderPage());
            });
        },

        /** Natural (scale 1) size of the current page / image. */
        async naturalSize() {
            if (isImage) return { width: imageEl.naturalWidth, height: imageEl.naturalHeight, page: null };
            const page = await pdfDoc.getPage(this.pageNum);
            const vp = page.getViewport({ scale: 1 });
            return { width: vp.width, height: vp.height, page };
        },

        async renderPage() {
            if (!pdfDoc && !imageEl) return;
            // Rapid page-nav/zoom clicks can start a second renderPage() before an
            // earlier one has come back from its own awaits - whichever call is the
            // last to *start* wins; every earlier one bails out when it resumes.
            const myGeneration = ++renderGeneration;

            const { width, height, page } = await this.naturalSize();
            if (myGeneration !== renderGeneration) return;

            if (this.fit) {
                const box = this.$refs.viewport;
                const availW = Math.max(200, (box?.clientWidth || 800) - 16);
                const availH = Math.max(200, (box?.clientHeight || window.innerHeight * 0.75) - 16);
                this.scale = this.fit === 'width' ? availW / width : Math.min(availW / width, availH / height);
            }

            const canvas = this.$refs.canvas;
            const ctx = canvas.getContext('2d');
            canvas.width = Math.round(width * this.scale);
            canvas.height = Math.round(height * this.scale);

            if (isImage) {
                ctx.drawImage(imageEl, 0, 0, canvas.width, canvas.height);
                this.$refs.textLayer.replaceChildren();
                return;
            }

            const viewport = page.getViewport({ scale: this.scale });
            if (renderTask) {
                renderTask.cancel();
                renderTask = null;
            }
            const task = page.render({ canvasContext: ctx, viewport });
            renderTask = task;
            try {
                await task.promise;
            } catch (e) {
                if (e?.name !== 'RenderingCancelledException') throw e;
            } finally {
                if (renderTask === task) renderTask = null;
            }
            if (myGeneration !== renderGeneration) return;

            await this.renderTextLayer(page, viewport);
        },

        /**
         * An invisible, precisely-positioned, selectable text layer over the canvas,
         * for text highlights and claim mapping. Built from pdf.js's core TextLayer
         * (see resources/css/app.css for the matching minimal CSS).
         */
        async renderTextLayer(page, viewport) {
            if (textLayerTask) {
                textLayerTask.cancel();
                textLayerTask = null;
            }
            const container = this.$refs.textLayer;
            container.replaceChildren();
            container.style.setProperty('--total-scale-factor', String(this.scale));

            try {
                const textContent = await page.getTextContent();
                const task = new pdfjsLib.TextLayer({ textContentSource: textContent, container, viewport });
                textLayerTask = task;
                await task.render();
            } catch (e) {
                console.error('Text layer render failed', e);
            }
        },

        // ---- navigation & view ----

        goToPage(n) {
            if (n < 1 || n > this.numPages || n === this.pageNum) return;
            this.pageNum = n;
            this.composer = null;
            this.openMapping = null;
            this.pendingMapping = null;
            this.cancelEditComposer();
            this.renderPage();
        },

        prevPage() {
            this.goToPage(this.pageNum - 1);
        },

        nextPage() {
            this.goToPage(this.pageNum + 1);
        },

        zoomIn() {
            this.fit = null;
            this.scale = Math.min(this.scale * 1.2, 5);
            this.renderPage();
        },

        zoomOut() {
            this.fit = null;
            this.scale = Math.max(this.scale / 1.2, 0.2);
            this.renderPage();
        },

        setFit(fit) {
            this.fit = fit;
            this.renderPage();
        },

        zoomLabel() {
            return Math.round(this.scale * 100) + '%';
        },

        async toggleFullscreen() {
            try {
                if (document.fullscreenElement) {
                    await document.exitFullscreen();
                } else {
                    await this.$refs.viewerRoot.requestFullscreen();
                }
            } catch (e) {
                console.error(e);
            }
        },

        setMode(mode) {
            this.modeMenuOpen = false;
            if (this.mode === mode) return;
            this.mode = mode;
            this.composer = null;
            this.pendingMapping = null;
            this.openMapping = null;
            this.cancelEditComposer();
            window.getSelection()?.removeAllRanges();
            if (mode === 'edit') this.ensureOriginalBytes();
        },

        // ---- comments: data helpers ----

        currentAnnotations() {
            return (this.annotations[this.pageNum] || []).filter((a) => this.showResolved || !a.resolved);
        },

        allAnnotations() {
            return Object.entries(this.annotations)
                .flatMap(([page, pins]) => pins.map((p) => ({ ...p, page: Number(page) })))
                .sort((a, b) => a.page - b.page || a.y_position - b.y_position || a.x_position - b.x_position);
        },

        panelItems() {
            return this.allAnnotations()
                .filter((a) => (this.showResolved || !a.resolved) && (this.panelScope === 'all' || a.page === this.pageNum));
        },

        /** Stable 1-based marker number for a comment, in reading order across the document. */
        markerNo(id) {
            return this.allAnnotations().findIndex((a) => a.id === id) + 1;
        },

        openCount() {
            return this.allAnnotations().filter((a) => !a.resolved).length;
        },

        findPin(id) {
            for (const pins of Object.values(this.annotations)) {
                const pin = pins.find((p) => p.id === id);
                if (pin) return pin;
            }
            return null;
        },

        textSelectable() {
            if (isImage) return false;
            return this.mode === 'reference' || (this.mode === 'comment' && this.commentTool === 'highlight');
        },

        setCommentTool(tool) {
            this.commentTool = tool;
            this.composer = null;
            window.getSelection()?.removeAllRanges();
        },

        selectComment(item) {
            if (this.mode !== 'comment') this.setMode('comment');
            if (item.resolved) this.showResolved = true;
            this.composer = null;
            this.selectedId = item.id;
            this.replyBody = '';
            this.editingId = null;
            if (item.page && item.page !== this.pageNum) this.goToPage(item.page);
            this.$nextTick(() => {
                document.getElementById('comment-card-' + item.id)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        },

        // ---- page interaction dispatcher ----

        onCanvasClick(event) {
            if (this.mode === 'comment') {
                if (this.commentTool === 'pin' && !event.target.closest('.pin')) {
                    const { x, y } = this.pctFromEvent(event);
                    this.openComposer({ kind: 'pin', x, y });
                }
                return;
            }
            if (this.mode === 'edit' && this.editTool === 'add_text' && !this.dragStart) {
                const { x, y } = this.pctFromEvent(event);
                this.textComposer = { x, y };
                this.textValue = '';
            }
        },

        dragging() {
            return (this.mode === 'edit' && this.editTool === 'redact') || (this.mode === 'comment' && this.commentTool === 'area');
        },

        onCanvasMouseDown(event) {
            if (!this.dragging()) return;
            event.preventDefault();
            this.dragStart = this.pctFromEvent(event);
            this.dragCurrent = this.dragStart;
        },

        onCanvasMouseMove(event) {
            if (!this.dragging() || !this.dragStart) return;
            this.dragCurrent = this.pctFromEvent(event);
        },

        onCanvasMouseUp() {
            if (!this.dragging() || !this.dragStart || !this.dragCurrent) return;
            const x = Math.min(this.dragStart.x, this.dragCurrent.x);
            const y = Math.min(this.dragStart.y, this.dragCurrent.y);
            const width = Math.abs(this.dragCurrent.x - this.dragStart.x);
            const height = Math.abs(this.dragCurrent.y - this.dragStart.y);
            this.dragStart = null;
            this.dragCurrent = null;
            if (width < 1 || height < 1) return; // ignore accidental clicks / tiny drags

            if (this.mode === 'comment') {
                const r = { x: +x.toFixed(2), y: +y.toFixed(2), w: +width.toFixed(2), h: +height.toFixed(2) };
                this.openComposer({ kind: 'area', x: r.x, y: r.y, rects: [r], text: '' });
                return;
            }

            this.pendingEdits.push({ _id: ++editIdSeq, edit_type: 'redact', page_number: this.pageNum, x, y, width, height, content: null });
        },

        pctFromEvent(event) {
            const rect = this.$refs.canvas.getBoundingClientRect();
            return {
                x: Math.min(100, Math.max(0, ((event.clientX - rect.left) / rect.width) * 100)),
                y: Math.min(100, Math.max(0, ((event.clientY - rect.top) / rect.height) * 100)),
            };
        },

        dragRectStyle() {
            if (!this.dragStart || !this.dragCurrent) return '';
            const x = Math.min(this.dragStart.x, this.dragCurrent.x);
            const y = Math.min(this.dragStart.y, this.dragCurrent.y);
            const width = Math.abs(this.dragCurrent.x - this.dragStart.x);
            const height = Math.abs(this.dragCurrent.y - this.dragStart.y);
            return `left:${x}%; top:${y}%; width:${width}%; height:${height}%;`;
        },

        // ---- new comment (composer lives in the side panel) ----

        openComposer(anchor) {
            this.composer = { ...anchor, x: +(+anchor.x).toFixed(2), y: +(+anchor.y).toFixed(2) };
            this.selectedId = null;
            this.newBody = '';
            this.newReplacement = '';
            this.$nextTick(() => this.$refs.composerBody?.focus());
        },

        cancelComposer() {
            this.composer = null;
            this.newBody = '';
            this.newReplacement = '';
            window.getSelection()?.removeAllRanges();
        },

        /**
         * Text selection -> one box per line (page percentages) + the selected words.
         * getClientRects() returns a box per text span, so boxes on the same line merge.
         */
        startHighlightComment() {
            const sel = window.getSelection();
            const text = sel ? sel.toString().trim() : '';
            if (!text || !sel.rangeCount) return;

            const canvasRect = this.$refs.canvas.getBoundingClientRect();
            const lines = [];
            for (const r of sel.getRangeAt(0).getClientRects()) {
                if (r.width < 1 || r.height < 1) continue;
                const line = lines.find((l) => Math.abs(l.top - r.top) < r.height / 2);
                if (line) {
                    line.left = Math.min(line.left, r.left);
                    line.right = Math.max(line.right, r.right);
                    line.top = Math.min(line.top, r.top);
                    line.bottom = Math.max(line.bottom, r.bottom);
                } else {
                    lines.push({ left: r.left, right: r.right, top: r.top, bottom: r.bottom });
                }
            }
            if (!lines.length) return;

            const pct = (v, origin, size) => Math.min(100, Math.max(0, ((v - origin) / size) * 100));
            const rects = lines
                .sort((a, b) => a.top - b.top)
                .map((l) => {
                    const x = pct(l.left, canvasRect.left, canvasRect.width);
                    const y = pct(l.top, canvasRect.top, canvasRect.height);
                    return {
                        x: +x.toFixed(2),
                        y: +y.toFixed(2),
                        w: +(pct(l.right, canvasRect.left, canvasRect.width) - x).toFixed(2),
                        h: +(pct(l.bottom, canvasRect.top, canvasRect.height) - y).toFixed(2),
                    };
                });

            this.openComposer({ kind: 'highlight', x: rects[0].x, y: rects[0].y, rects, text: text.slice(0, 2000) });
        },

        onTextSelected() {
            if (this.mode === 'comment' && this.commentTool === 'highlight') {
                this.startHighlightComment();
                return;
            }
            if (this.mode === 'reference') this.startMapping();
        },

        async postComment(payload) {
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error('Request failed');
            return (await res.json()).comment;
        },

        async submitComposer() {
            if (!this.composer || !this.newBody.trim() || this.posting) return;
            this.posting = true;
            try {
                const payload = {
                    body: this.newBody,
                    page_number: this.pageNum,
                    x_position: this.composer.x,
                    y_position: this.composer.y,
                };
                if (this.composer.rects) {
                    payload.highlight_rects = this.composer.rects;
                    payload.selected_text = this.composer.text || null;
                    payload.replacement_text = this.newReplacement.trim() || null;
                }
                const comment = await this.postComment(payload);
                if (!this.annotations[this.pageNum]) this.annotations[this.pageNum] = [];
                this.annotations[this.pageNum].push({
                    ...comment,
                    created_at: 'just now',
                    created_at_iso: new Date().toISOString(),
                    resolved: false,
                    resolved_by: null,
                    from_version: null,
                    can_resolve: true,
                    can_edit: true,
                    edited: false,
                    replies: [],
                });
                this.cancelComposer();
                this.selectedId = comment.id;
            } catch (e) {
                alert('Could not post the comment. Please try again.');
            } finally {
                this.posting = false;
            }
        },

        async submitReply(pin) {
            if (!this.replyBody.trim() || this.posting) return;
            this.posting = true;
            try {
                const reply = await this.postComment({ body: this.replyBody, parent_id: pin.id });
                pin.replies.push({ ...reply, created_at: 'just now', created_at_iso: new Date().toISOString(), can_edit: true, edited: false });
                this.replyBody = '';
            } catch (e) {
                alert('Could not post the reply. Please try again.');
            } finally {
                this.posting = false;
            }
        },

        // ---- edit your own comment / reply ----

        startEdit(item) {
            this.editingId = item.id;
            this.editBody = item.body;
            this.editReplacement = item.replacement_text || '';
        },

        async saveEdit(item) {
            if (!this.editBody.trim() || this.posting) return;
            this.posting = true;
            try {
                const res = await fetch(commentUpdateUrlTemplate.replace('__ID__', item.id), {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    body: JSON.stringify({ body: this.editBody, replacement_text: this.editReplacement.trim() || null }),
                });
                if (!res.ok) throw new Error('Request failed');
                const { comment } = await res.json();
                item.body = comment.body;
                if ('replacement_text' in item) item.replacement_text = comment.replacement_text;
                item.edited = true;
                this.editingId = null;
            } catch (e) {
                alert('Could not save your edit. Please try again.');
            } finally {
                this.posting = false;
            }
        },

        async toggleResolved(id) {
            const pin = this.findPin(id);
            if (!pin || this.posting) return;
            this.posting = true;
            try {
                const res = await fetch(resolveUrlTemplate.replace('__ID__', pin.id), {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    body: JSON.stringify({ resolved: !pin.resolved }),
                });
                if (!res.ok) throw new Error('Request failed');
                const data = await res.json();
                pin.resolved = data.resolved;
                pin.resolved_by = data.resolved_by;
                if (pin.resolved && !this.showResolved) this.selectedId = null;
            } catch (e) {
                alert('Could not update the comment. Please try again.');
            } finally {
                this.posting = false;
            }
        },

        // ---- REQ-3.3: claim <-> reference mapping (mode: 'reference') ----

        currentMappings() {
            return this.mappings[this.pageNum] || [];
        },

        startMapping() {
            const sel = window.getSelection();
            const text = sel ? sel.toString().trim() : '';
            if (!text || !sel.rangeCount) return;

            const selRect = sel.getRangeAt(0).getBoundingClientRect();
            if (selRect.width === 0 && selRect.height === 0) return;

            const canvasRect = this.$refs.canvas.getBoundingClientRect();
            const x = ((selRect.left + selRect.width / 2 - canvasRect.left) / canvasRect.width) * 100;
            const y = ((selRect.top - canvasRect.top) / canvasRect.height) * 100;

            this.pendingMapping = { x: x.toFixed(2), y: y.toFixed(2), text: text.slice(0, 500) };
            this.mappingClaimId = '';
            this.mappingReferenceId = '';
            this.openMapping = null;
        },

        cancelMapping() {
            this.pendingMapping = null;
            window.getSelection()?.removeAllRanges();
        },

        async submitMapping() {
            if (!this.pendingMapping || this.posting) return;
            if (!this.mappingClaimId && !this.mappingReferenceId) {
                alert('Pick a claim and/or a reference to link this text to.');
                return;
            }
            this.posting = true;
            try {
                const res = await fetch(mappingStoreUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    body: JSON.stringify({
                        page_number: this.pageNum,
                        x_position: this.pendingMapping.x,
                        y_position: this.pendingMapping.y,
                        selected_text: this.pendingMapping.text,
                        claim_id: this.mappingClaimId || null,
                        reference_attachment_id: this.mappingReferenceId || null,
                    }),
                });
                if (!res.ok) throw new Error('Request failed');
                const { mapping } = await res.json();
                if (!this.mappings[this.pageNum]) this.mappings[this.pageNum] = [];
                this.mappings[this.pageNum].push(mapping);
                this.cancelMapping();
            } catch (e) {
                alert('Could not save the mapping. Please try again.');
            } finally {
                this.posting = false;
            }
        },

        async deleteMapping(mapping) {
            if (!confirm('Remove this mapping?')) return;
            try {
                const res = await fetch(mappingDestroyUrlBase + mapping.id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                });
                if (!res.ok) throw new Error('Request failed');
                this.mappings[this.pageNum] = this.currentMappings().filter((m) => m.id !== mapping.id);
                this.openMapping = null;
            } catch (e) {
                alert('Could not remove the mapping. Please try again.');
            }
        },

        // ---- raw PDF bytes (edit save + export) ----

        async ensureOriginalBytes() {
            if (originalBytes || isImage) return;
            if (fetchingOriginalBytes) return fetchingOriginalBytes;
            fetchingOriginalBytes = (async () => {
                try {
                    const res = await fetch(pdfUrl, { credentials: 'include' });
                    if (!res.ok) throw new Error('Could not download the PDF (' + res.status + ')');
                    originalBytes = await res.arrayBuffer();
                } catch (e) {
                    this.saveError = 'Could not load this PDF for editing (' + (e?.message || 'unknown error') + ').';
                    console.error(e);
                } finally {
                    fetchingOriginalBytes = null;
                }
            })();
            return fetchingOriginalBytes;
        },

        // ---- export: the PDF with every review comment embedded as a real PDF annotation ----

        /**
         * Each pin becomes a sticky note and each highlight/area a highlight/square
         * annotation (replies threaded under them via /IRT), so the review travels
         * with the file and opens with its comments in Acrobat, Edge, Preview, etc.
         * Same top-left-% -> PDF bottom-left-points flip as saveEdits(), offset by
         * the CropBox origin.
         */
        async downloadWithComments() {
            if (this.exporting || isImage) return;
            this.exporting = true;
            this.exportError = null;
            try {
                await this.ensureOriginalBytes();
                if (!originalBytes) throw new Error('PDF not loaded yet');

                const doc = await PDFDocument.load(originalBytes.slice(0), { ignoreEncryption: true });
                const ctx = doc.context;
                const pages = doc.getPages();
                const text = (v) => PDFHexString.fromText(String(v ?? ''));
                const date = (iso) => PDFString.fromDate(iso ? new Date(iso) : new Date());

                for (const [pageNo, pins] of Object.entries(this.annotations)) {
                    const page = pages[Number(pageNo) - 1];
                    if (!page) continue;
                    const box = page.getCropBox();
                    const px = (p) => box.x + (p / 100) * box.width;
                    const py = (p) => box.y + box.height - (p / 100) * box.height;

                    for (const pin of pins) {
                        const replace = pin.replacement_text
                            ? `Replace "${pin.selected_text || 'marked area'}" with "${pin.replacement_text}". `
                            : '';
                        const common = {
                            Type: 'Annot',
                            F: 4, // print
                            P: page.ref,
                            T: text(pin.author?.name),
                            M: date(pin.created_at_iso),
                            Contents: text((pin.resolved ? '[Resolved] ' : '') + replace + pin.body),
                            C: pin.resolved ? [0.6, 0.6, 0.6] : [1, 0.8, 0.1],
                        };

                        let rect;
                        let parent;
                        if (pin.highlight_rects?.length) {
                            const quads = [];
                            let [x1, y1, x2, y2] = [Infinity, Infinity, -Infinity, -Infinity];
                            for (const r of pin.highlight_rects) {
                                const l = px(r.x), rt = px(r.x + r.w), t = py(r.y), b = py(r.y + r.h);
                                quads.push(l, t, rt, t, l, b, rt, b);
                                x1 = Math.min(x1, l); x2 = Math.max(x2, rt);
                                y1 = Math.min(y1, b); y2 = Math.max(y2, t);
                            }
                            rect = [x1, y1, x2, y2];
                            parent = pin.selected_text
                                ? ctx.obj({ ...common, Subtype: 'Highlight', Rect: rect, QuadPoints: quads, CA: 0.4 })
                                : ctx.obj({ ...common, Subtype: 'Square', Rect: rect, BS: { W: 1.5 } });
                        } else {
                            const x = px(pin.x_position), y = py(pin.y_position);
                            rect = [x - 10, y - 10, x + 10, y + 10];
                            parent = ctx.obj({ ...common, Subtype: 'Text', Rect: rect, Name: 'Comment', Open: false });
                        }
                        const parentRef = ctx.register(parent);
                        page.node.addAnnot(parentRef);

                        for (const reply of pin.replies || []) {
                            const replyRef = ctx.register(ctx.obj({
                                Type: 'Annot',
                                Subtype: 'Text',
                                F: 4,
                                P: page.ref,
                                Rect: rect,
                                IRT: parentRef,
                                Name: 'Comment',
                                Open: false,
                                T: text(reply.author?.name),
                                M: date(reply.created_at_iso),
                                Contents: text(reply.body),
                            }));
                            page.node.addAnnot(replyRef);
                        }
                    }
                }

                const bytes = await doc.save();
                const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
                const a = document.createElement('a');
                a.href = url;
                a.download = (originalFilename || 'document.pdf').replace(/\.pdf$/i, '') + '-with-comments.pdf';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 10000);
            } catch (e) {
                this.exportError = 'Could not build the commented PDF (' + (e?.message || 'unknown error') + ').';
                console.error(e);
            } finally {
                this.exporting = false;
            }
        },

        // ---- content edits: add text / redact (mode: 'edit', PDFs, owner) ----

        setEditTool(tool) {
            this.editTool = this.editTool === tool ? null : tool;
            this.cancelEditComposer();
        },

        cancelEditComposer() {
            this.textComposer = null;
            this.textValue = '';
            this.dragStart = null;
            this.dragCurrent = null;
        },

        editsForPage() {
            return this.pendingEdits.filter((e) => e.page_number === this.pageNum);
        },

        submitTextEdit() {
            if (!this.textValue.trim() || !this.textComposer) return;
            this.pendingEdits.push({
                _id: ++editIdSeq,
                edit_type: 'add_text',
                page_number: this.pageNum,
                x: this.textComposer.x,
                y: this.textComposer.y,
                width: null,
                height: null,
                content: this.textValue.trim(),
            });
            this.cancelEditComposer();
        },

        removeEdit(id) {
            this.pendingEdits = this.pendingEdits.filter((e) => e._id !== id);
        },

        /**
         * Bakes every pending edit into a fresh copy of the ORIGINAL PDF bytes with
         * pdf-lib, then uploads the result as a new document version.
         */
        async saveEdits() {
            if (this.pendingEdits.length === 0 || this.saving) return;
            this.saving = true;
            this.saveError = null;

            try {
                await this.ensureOriginalBytes();
                if (!originalBytes) throw new Error('PDF not loaded yet');

                const doc = await PDFDocument.load(originalBytes.slice(0));
                const font = await doc.embedFont(StandardFonts.Helvetica);
                const pages = doc.getPages();

                for (const edit of this.pendingEdits) {
                    const page = pages[edit.page_number - 1];
                    if (!page) continue;
                    const { width: pw, height: ph } = page.getSize();

                    if (edit.edit_type === 'redact') {
                        const x = (edit.x / 100) * pw;
                        const boxHeight = (edit.height / 100) * ph;
                        const y = ph - (edit.y / 100) * ph - boxHeight;
                        const width = (edit.width / 100) * pw;
                        page.drawRectangle({
                            x, y, width, height: boxHeight,
                            color: rgb(0.55, 0.11, 0.11),
                            borderColor: rgb(0.35, 0.05, 0.05),
                            borderWidth: 1,
                        });
                    } else {
                        const fontSize = 12;
                        const x = (edit.x / 100) * pw;
                        const y = ph - (edit.y / 100) * ph;
                        const textWidth = font.widthOfTextAtSize(edit.content, fontSize);
                        page.drawRectangle({
                            x: x - 2, y: y - 3, width: textWidth + 4, height: fontSize + 5,
                            color: rgb(1, 0.92, 0.7),
                            opacity: 0.85,
                            borderColor: rgb(0.85, 0.6, 0.05),
                            borderWidth: 0.75,
                        });
                        page.drawText(edit.content, { x, y, size: fontSize, font, color: rgb(0.05, 0.05, 0.05) });
                    }
                }

                const bytes = await doc.save();
                const blob = new Blob([bytes], { type: 'application/pdf' });
                const filename = (originalFilename || 'document.pdf').replace(/\.pdf$/i, '') + '-edited.pdf';

                const formData = new FormData();
                formData.append('file', blob, filename);
                formData.append('change_notes', this.changeNotes);
                this.pendingEdits.forEach((edit, i) => {
                    formData.append(`edits[${i}][edit_type]`, edit.edit_type);
                    formData.append(`edits[${i}][page_number]`, edit.page_number);
                    formData.append(`edits[${i}][x]`, edit.x);
                    formData.append(`edits[${i}][y]`, edit.y);
                    if (edit.width != null) formData.append(`edits[${i}][width]`, edit.width);
                    if (edit.height != null) formData.append(`edits[${i}][height]`, edit.height);
                    if (edit.content != null) formData.append(`edits[${i}][content]`, edit.content);
                });

                const res = await fetch(pdfEditStoreUrl, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData,
                });

                if (!res.ok) {
                    const body = await res.json().catch(() => null);
                    throw new Error(body?.message || 'Save failed (' + res.status + ')');
                }

                window.location.reload();
            } catch (e) {
                this.saveError = e?.message || 'Could not save the edited PDF. Please try again.';
                console.error(e);
            } finally {
                this.saving = false;
            }
        },
    };
}
