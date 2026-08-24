import * as pdfjsLib from 'pdfjs-dist';
import { PDFDocument, rgb, StandardFonts } from 'pdf-lib';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.mjs', import.meta.url).href;

/**
 * Real, in-place PDF content editor. Two tools:
 *  - Add Text: click a spot, type a note, it's drawn onto the page with a highlight
 *    box behind it so it's visually obvious this text was added.
 *  - Redact: drag a rectangle over existing content; a solid colored box is drawn
 *    over that region on save, genuinely covering/removing it from view.
 * Nothing is mutated until "Save as New Version" - at that point every pending edit
 * is applied with pdf-lib to a fresh copy of the *original* PDF bytes (kept in
 * closure scope, never touched by pdf.js's own parsed document - see the private-
 * field note in pdf-viewer.js for why pdf.js objects stay out of Alpine's reactive
 * x-data), producing a real new PDF file uploaded as a normal new document version.
 * The edit log (who/what/where) is uploaded alongside it and stored permanently.
 */
export default function pdfContentEditor({ pdfUrl, csrfToken, storeUrl, originalFilename }) {
    let pdfDoc = null; // pdf.js parsed doc, for rendering only
    let originalBytes = null; // raw PDF bytes, for pdf-lib to mutate on save
    let editIdSeq = 0;
    let renderTask = null; // in-flight pdf.js page.render() call, so a second render (zoom/page-nav fired before the first finishes) cancels it first rather than both racing the same canvas
    let renderGeneration = 0; // bumped on every renderPage() call; a call that resumes from its `await` after a newer one has started abandons itself rather than drawing stale/racing content
    let initStarted = false; // Alpine's x-init has fired more than once in some browser/navigation states; guard against loading + rendering twice

    return {
        pageNum: 1,
        numPages: 0,
        scale: 1.2,
        loading: true,
        error: null,
        editMode: null, // null | 'add_text' | 'redact'
        pendingEdits: [],
        textComposer: null, // { x, y }
        textValue: '',
        dragStart: null,
        dragCurrent: null,
        changeNotes: '',
        saving: false,
        saveError: null,

        async init() {
            if (initStarted) return;
            initStarted = true;
            try {
                const res = await fetch(pdfUrl, { credentials: 'include' });
                if (!res.ok) throw new Error('Could not download the PDF (' + res.status + ')');
                originalBytes = await res.arrayBuffer();

                const loadingTask = pdfjsLib.getDocument({ data: originalBytes.slice(0) });
                pdfDoc = await loadingTask.promise;
                this.numPages = pdfDoc.numPages;
                await this.renderPage();
            } catch (e) {
                this.error = 'Could not load this PDF for editing (' + (e?.message || 'unknown error') + ').';
                console.error(e);
            } finally {
                this.loading = false;
            }
        },

        async renderPage() {
            if (!pdfDoc) return;
            // Rapid clicks (zoom +, page-nav) can fire renderPage() again before an
            // earlier call has come back from its own `await`s. A simple "cancel the
            // previous task" isn't enough on its own - two calls can both still be
            // mid-flight through the same async gap with neither's task set yet, so
            // both go on to call canvas.render() and pdf.js throws "Cannot use the
            // same canvas during multiple render() operations." The generation
            // counter closes that gap: whichever call is the last to *start* wins,
            // and every earlier one notices it's stale as soon as it resumes and
            // bails out before ever touching the canvas.
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
        },

        prevPage() { if (this.pageNum > 1) { this.pageNum--; this.cancelComposer(); this.renderPage(); } },
        nextPage() { if (this.pageNum < this.numPages) { this.pageNum++; this.cancelComposer(); this.renderPage(); } },
        zoomIn() { this.scale = Math.min(this.scale + 0.2, 3); this.renderPage(); },
        zoomOut() { this.scale = Math.max(this.scale - 0.2, 0.5); this.renderPage(); },

        setMode(mode) {
            this.editMode = this.editMode === mode ? null : mode;
            this.cancelComposer();
        },

        cancelComposer() {
            this.textComposer = null;
            this.textValue = '';
            this.dragStart = null;
            this.dragCurrent = null;
        },

        editsForPage() {
            return this.pendingEdits.filter((e) => e.page_number === this.pageNum);
        },

        pctFromEvent(event) {
            const rect = this.$refs.canvas.getBoundingClientRect();
            return {
                x: Math.min(100, Math.max(0, ((event.clientX - rect.left) / rect.width) * 100)),
                y: Math.min(100, Math.max(0, ((event.clientY - rect.top) / rect.height) * 100)),
            };
        },

        onCanvasClick(event) {
            if (this.editMode !== 'add_text' || this.dragStart) return;
            const { x, y } = this.pctFromEvent(event);
            this.textComposer = { x, y };
            this.textValue = '';
        },

        onCanvasMouseDown(event) {
            if (this.editMode !== 'redact') return;
            this.dragStart = this.pctFromEvent(event);
            this.dragCurrent = this.dragStart;
        },

        onCanvasMouseMove(event) {
            if (this.editMode !== 'redact' || !this.dragStart) return;
            this.dragCurrent = this.pctFromEvent(event);
        },

        onCanvasMouseUp() {
            if (this.editMode !== 'redact' || !this.dragStart || !this.dragCurrent) return;
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
            this.cancelComposer();
        },

        removeEdit(id) {
            this.pendingEdits = this.pendingEdits.filter((e) => e._id !== id);
        },

        /**
         * Bakes every pending edit into a fresh copy of the ORIGINAL PDF bytes with
         * pdf-lib, then uploads the result as a new document version. pdf-lib's
         * coordinate origin is bottom-left (PDF native), while our pins are
         * top-left percentages (matching the rest of the app's annotation UI) - the
         * y-flip below is the only place that conversion happens.
         */
        async saveEdits() {
            if (this.pendingEdits.length === 0 || this.saving) return;
            this.saving = true;
            this.saveError = null;

            try {
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

                const res = await fetch(storeUrl, {
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
