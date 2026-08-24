import * as pdfjsLib from 'pdfjs-dist';
import { PDFDocument, rgb, StandardFonts } from 'pdf-lib';

// pdf.js needs a worker script; Vite bundles it as its own asset and hands us the URL.
pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.mjs', import.meta.url).href;

/**
 * Alpine.js component powering the in-page PDF viewer. One `mode` drives what a
 * click on the page does — replacing what used to be several separate buttons
 * (a comment pin was always-on, "Map claim ↔ reference" was its own toggle, and
 * "Edit PDF Content" was a whole separate page/component, pdf-editor.js). The
 * single mode dropdown in the toolbar (documents/partials/pdf-viewer.blade.php)
 * now switches between:
 *   - 'comment'   click-to-place page/x/y anchored comment pins (the old default)
 *   - 'reference' (REQ-3.3) select PDF text to pin a claim/reference pair
 *   - 'edit'      real, in-place PDF content edits (add text / redact), folded in
 *                 from the former standalone pdf-editor.js/pdf-editor.blade.php
 */
export default function pdfAnnotationViewer({
    pdfUrl, csrfToken, storeUrl, initialAnnotations,
    mappingStoreUrl, mappingDestroyUrlBase, documentClaims, documentReferences, initialMappings,
    pdfEditStoreUrl, originalFilename,
}) {
    // Deliberately kept OUTSIDE the returned Alpine data object, in closure scope: Alpine
    // makes every property on x-data reactive by wrapping it in a Proxy, and pdf.js's
    // PDFDocumentProxy/PDFPageProxy/TextLayer (v6+) use private class fields (#field)
    // internally. Calling a method/getter that touches a private field *through* a Proxy
    // throws "Cannot read from private field", because the private field is tied to the
    // real object's identity, not a proxy wrapping it. Keeping these here means Alpine
    // never sees them and never wraps them.
    let pdfDoc = null;
    let textLayerTask = null;
    let renderTask = null;
    let renderGeneration = 0; // see below - a simple "cancel the previous task" isn't enough on its own
    let originalBytes = null; // raw PDF bytes, fetched lazily on first entry into Edit mode, for pdf-lib to mutate on save
    let fetchingOriginalBytes = null; // in-flight fetch promise, so switching to Edit mode twice quickly doesn't double-fetch
    let editIdSeq = 0;

    return {
        pageNum: 1,
        numPages: 0,
        scale: 1.2,
        loading: true,
        error: null,
        annotations: initialAnnotations || {},
        pendingPin: null,
        newBody: '',
        openPin: null,
        replyBody: '',
        posting: false,

        // Which of the three tools the page click/drag currently drives.
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

        // Edit mode state (folded in from the former pdf-editor.js).
        editTool: null, // null | 'add_text' | 'redact'
        pendingEdits: [],
        textComposer: null, // { x, y }
        textValue: '',
        dragStart: null,
        dragCurrent: null,
        changeNotes: '',
        saving: false,
        saveError: null,

        async init() {
            try {
                // withCredentials is required here: this URL sits behind Laravel's auth
                // middleware, and pdf.js's internal fetch doesn't send the session cookie
                // unless told to - without it, the request gets redirected to /login and
                // pdf.js tries (and fails) to parse the login page HTML as a PDF.
                const loadingTask = pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true });
                pdfDoc = await loadingTask.promise;
                this.numPages = pdfDoc.numPages;
                await this.renderPage();
            } catch (e) {
                this.error = 'Could not load this PDF for preview (' + (e?.message || 'unknown error') + '). You can still download it above.';
                console.error(e);
            } finally {
                this.loading = false;
            }
        },

        async renderPage() {
            if (!pdfDoc) return;
            // Rapid page-nav/zoom clicks can start a second renderPage() before an
            // earlier one has come back from its own awaits - without this guard both
            // go on to call canvas render() and pdf.js throws "Cannot use the same
            // canvas during multiple render() operations." Whichever call is the last
            // to *start* wins; every earlier one notices it's stale as soon as it
            // resumes and bails out before touching the canvas.
            const myGeneration = ++renderGeneration;

            const page = await pdfDoc.getPage(this.pageNum);
            if (myGeneration !== renderGeneration) return;

            const viewport = page.getViewport({ scale: this.scale });
            const canvas = this.$refs.canvas;
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            const ctx = canvas.getContext('2d');

            if (renderTask) {
                renderTask.cancel();
                renderTask = null;
            }
            if (myGeneration !== renderGeneration) return;

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
         * REQ-3.3: an invisible, precisely-positioned, selectable text layer over the
         * canvas so a user can drag-select real PDF text (not just click a spot) to pin
         * to a claim/reference. Built from pdf.js's core TextLayer class directly rather
         * than importing its full web/pdf_viewer bundle (which assumes a whole viewer
         * app around it) - see resources/css/app.css for the matching minimal CSS.
         */
        async renderTextLayer(page, viewport) {
            if (textLayerTask) {
                textLayerTask.cancel();
                textLayerTask = null;
            }
            const container = this.$refs.textLayer;
            container.replaceChildren();
            // TextLayer's per-span font-size is computed via CSS from --total-scale-factor
            // (a contract normally supplied by pdf.js's own viewer app, which we don't use)
            // - set it directly to our zoom level so glyph sizing lines up with the canvas.
            container.style.setProperty('--total-scale-factor', String(this.scale));

            try {
                const textContent = await page.getTextContent();
                const task = new pdfjsLib.TextLayer({ textContentSource: textContent, container, viewport });
                textLayerTask = task;
                await task.render();
            } catch (e) {
                // Selectable text is a nice-to-have for mapping mode; never block the visible
                // page render over it.
                console.error('Text layer render failed', e);
            }
        },

        prevPage() {
            if (this.pageNum > 1) {
                this.pageNum--;
                this.openPin = null;
                this.pendingPin = null;
                this.openMapping = null;
                this.pendingMapping = null;
                this.cancelEditComposer();
                this.renderPage();
            }
        },

        nextPage() {
            if (this.pageNum < this.numPages) {
                this.pageNum++;
                this.openPin = null;
                this.pendingPin = null;
                this.openMapping = null;
                this.pendingMapping = null;
                this.cancelEditComposer();
                this.renderPage();
            }
        },

        zoomIn() {
            this.scale = Math.min(this.scale + 0.2, 3);
            this.renderPage();
        },

        zoomOut() {
            this.scale = Math.max(this.scale - 0.2, 0.5);
            this.renderPage();
        },

        // ---- single mode switch: replaces the old always-on comment behavior +
        // separate "Map claim ↔ reference" toggle + separate Edit PDF Content page ----

        setMode(mode) {
            this.modeMenuOpen = false;
            if (this.mode === mode) return;
            this.mode = mode;
            this.pendingPin = null;
            this.openPin = null;
            this.pendingMapping = null;
            this.openMapping = null;
            this.cancelEditComposer();
            window.getSelection()?.removeAllRanges();
            if (mode === 'edit') this.ensureOriginalBytes();
        },

        currentAnnotations() {
            return this.annotations[this.pageNum] || [];
        },

        // ---- canvas interaction dispatcher: routes clicks/drags to whichever tool the current mode uses ----

        onCanvasClick(event) {
            if (this.mode === 'comment') { this.placePin(event); return; }
            if (this.mode === 'edit' && this.editTool === 'add_text' && !this.dragStart) {
                const { x, y } = this.pctFromEvent(event);
                this.textComposer = { x, y };
                this.textValue = '';
            }
        },

        onCanvasMouseDown(event) {
            if (this.mode !== 'edit' || this.editTool !== 'redact') return;
            this.dragStart = this.pctFromEvent(event);
            this.dragCurrent = this.dragStart;
        },

        onCanvasMouseMove(event) {
            if (this.mode !== 'edit' || this.editTool !== 'redact' || !this.dragStart) return;
            this.dragCurrent = this.pctFromEvent(event);
        },

        onCanvasMouseUp() {
            if (this.mode !== 'edit' || this.editTool !== 'redact' || !this.dragStart || !this.dragCurrent) return;
            const x = Math.min(this.dragStart.x, this.dragCurrent.x);
            const y = Math.min(this.dragStart.y, this.dragCurrent.y);
            const width = Math.abs(this.dragCurrent.x - this.dragStart.x);
            const height = Math.abs(this.dragCurrent.y - this.dragStart.y);
            this.dragStart = null;
            this.dragCurrent = null;
            if (width < 1 || height < 1) return; // ignore accidental clicks/tiny drags

            this.pendingEdits.push({
                _id: ++editIdSeq,
                edit_type: 'redact',
                page_number: this.pageNum,
                x, y, width, height,
                content: null,
            });
        },

        pctFromEvent(event) {
            const rect = this.$refs.canvas.getBoundingClientRect();
            return {
                x: Math.min(100, Math.max(0, ((event.clientX - rect.left) / rect.width) * 100)),
                y: Math.min(100, Math.max(0, ((event.clientY - rect.top) / rect.height) * 100)),
            };
        },

        // ---- comment pins (mode: 'comment') ----

        placePin(event) {
            if (event.target.closest('.pin')) return;
            const rect = this.$refs.canvas.getBoundingClientRect();
            const x = ((event.clientX - rect.left) / rect.width) * 100;
            const y = ((event.clientY - rect.top) / rect.height) * 100;
            this.pendingPin = { x: x.toFixed(2), y: y.toFixed(2) };
            this.openPin = null;
            this.newBody = '';
        },

        cancelPending() {
            this.pendingPin = null;
            this.newBody = '';
        },

        async postComment(payload) {
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                throw new Error('Request failed');
            }

            return (await res.json()).comment;
        },

        async submitPin() {
            if (!this.newBody.trim() || this.posting) return;
            this.posting = true;
            try {
                const comment = await this.postComment({
                    body: this.newBody,
                    page_number: this.pageNum,
                    x_position: this.pendingPin.x,
                    y_position: this.pendingPin.y,
                });
                if (!this.annotations[this.pageNum]) this.annotations[this.pageNum] = [];
                this.annotations[this.pageNum].push({ ...comment, replies: [] });
                this.pendingPin = null;
                this.newBody = '';
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
                pin.replies.push(reply);
                this.replyBody = '';
            } catch (e) {
                alert('Could not post the reply. Please try again.');
            } finally {
                this.posting = false;
            }
        },

        // ---- REQ-3.3: claim <-> reference mapping (mode: 'reference') ----

        currentMappings() {
            return this.mappings[this.pageNum] || [];
        },

        onTextSelected() {
            if (this.mode !== 'reference') return;
            const sel = window.getSelection();
            const text = sel ? sel.toString().trim() : '';
            if (!text || !sel.rangeCount) return;

            const range = sel.getRangeAt(0);
            const selRect = range.getBoundingClientRect();
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
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
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

        // ---- content edits: add text / redact (mode: 'edit', folded in from the former pdf-editor.js) ----

        /**
         * pdf.js (above) streams the PDF straight from its URL for rendering. Real
         * content edits need the raw bytes too, so pdf-lib can mutate a fresh copy of
         * them on save - fetched once, lazily, the first time Edit mode is entered
         * (never for users who only comment or map references).
         */
        async ensureOriginalBytes() {
            if (originalBytes) return;
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

        dragRectStyle() {
            if (!this.dragStart || !this.dragCurrent) return '';
            const x = Math.min(this.dragStart.x, this.dragCurrent.x);
            const y = Math.min(this.dragStart.y, this.dragCurrent.y);
            const width = Math.abs(this.dragCurrent.x - this.dragStart.x);
            const height = Math.abs(this.dragCurrent.y - this.dragStart.y);
            return `left:${x}%; top:${y}%; width:${width}%; height:${height}%;`;
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
         * pdf-lib, then uploads the result as a new document version. pdf-lib's
         * coordinate origin is bottom-left (PDF native), while our pins are
         * top-left percentages (matching the rest of this app's annotation UI) - the
         * y-flip below is the only place that conversion happens.
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
                        // Highlight box behind the added text, so the addition reads as
                        // a visible, deliberate marker rather than blending in silently.
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
