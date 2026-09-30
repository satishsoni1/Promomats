/**
 * In-page Word review with SuperDoc: opens the current .docx in a browser-based
 * Word editor with Track Changes and Word comments, and saves the result back as
 * a new document version (WordReviewController::store). Everything runs in the
 * browser - no document server - so there's no limit on concurrent reviewers.
 *
 * What the user may do comes from the server (WordReviewController::editorRole):
 *   editor    full editing, can accept/reject tracked changes
 *   suggester every edit becomes a tracked change (Word's "Suggesting")
 *   viewer    read-only, tracked changes + comments shown (legal hold)
 *
 * SuperDoc (~several MB) is loaded on demand, so pages without a Word document
 * never download it.
 */
const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

export default function wordReviewEditor({ fileUrl, saveUrl, csrfToken, baseVersionId, role, user, filename }) {
    // Kept outside the Alpine data object (like pdf.js in pdf-viewer.js) so Alpine
    // never wraps the SuperDoc instance in a reactive Proxy.
    let superdoc = null;

    return {
        loading: true,
        error: null,
        role,
        dirty: false,
        saving: false,
        saveError: null,
        savedVersionNo: null,
        changeNotes: '',

        async init() {
            try {
                const [{ SuperDoc }, res] = await Promise.all([
                    import('superdoc'),
                    fetch(fileUrl, { credentials: 'include' }),
                    import('superdoc/style.css'),
                ]);
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const file = new File([await res.blob()], filename, { type: DOCX_MIME });

                superdoc = new SuperDoc({
                    selector: this.$refs.editor,
                    document: file,
                    role,
                    documentMode: role === 'editor' ? 'editing' : (role === 'suggester' ? 'suggesting' : 'viewing'),
                    viewing: { comments: true, trackedChanges: 'markup' },
                    user,
                    ui: { toolbar: { container: this.$refs.toolbar } },
                    contained: true,
                    // Confidential client material: never report document opens to a third party.
                    telemetry: { enabled: false },
                    onReady: () => {
                        this.loading = false;
                    },
                    onEditorUpdate: () => {
                        this.dirty = true;
                    },
                    onCommentsUpdate: () => {
                        if (!this.loading) this.dirty = true;
                    },
                    onException: ({ error }) => {
                        console.error(error);
                        this.error = 'Could not open this Word document (' + (error?.message || 'unknown error') + '). You can still download it from the Versions list.';
                        this.loading = false;
                    },
                });

                window.addEventListener('beforeunload', (e) => {
                    if (this.dirty && !this.saving) e.preventDefault();
                });
            } catch (e) {
                console.error(e);
                this.error = 'Could not open this Word document (' + (e?.message || 'unknown error') + '). You can still download it from the Versions list.';
                this.loading = false;
            }
        },

        exportDocx() {
            // 'external' keeps the Word comments in the file, so they open in desktop Word too.
            return superdoc.export({ exportType: ['docx'], commentsType: 'external', triggerDownload: false });
        },

        async save() {
            if (!superdoc || this.saving || this.role === 'viewer') return;
            this.saving = true;
            this.saveError = null;
            try {
                const blob = await this.exportDocx();
                const form = new FormData();
                form.append('file', new File([blob], filename.replace(/\.docx$/i, '') + '.docx', { type: DOCX_MIME }));
                form.append('base_version_id', baseVersionId);
                form.append('change_notes', this.changeNotes);

                const res = await fetch(saveUrl, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    body: form,
                });
                const body = await res.json().catch(() => null);
                if (!res.ok) throw new Error(body?.message || 'Save failed (' + res.status + ')');

                this.dirty = false;
                this.savedVersionNo = body.version_no;
                // Reload so the Versions list, workflow panel and this editor all
                // point at the version just created.
                setTimeout(() => window.location.reload(), 1200);
            } catch (e) {
                console.error(e);
                this.saveError = e?.message || 'Could not save. Please try again.';
            } finally {
                this.saving = false;
            }
        },

        // Safety net, e.g. after a "newer version exists" conflict: keep a local copy.
        async downloadCopy() {
            if (!superdoc) return;
            const url = URL.createObjectURL(await this.exportDocx());
            const a = document.createElement('a');
            a.href = url;
            a.download = filename.replace(/\.docx$/i, '') + '-review.docx';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 10000);
        },
    };
}
