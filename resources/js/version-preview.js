/**
 * "View" in the Versions list: opens any version in an in-page preview window
 * without downloading it. PDFs, images and video use the browser's own viewers
 * (served inline by DocumentController::viewVersion); .docx renders read-only in
 * SuperDoc, with tracked changes and comments shown. Download stays one click
 * away inside the window.
 */
export default function versionPreview() {
    // Kept outside Alpine's reactive state (see pdf-viewer.js for why).
    let superdoc = null;

    return {
        current: null, // { url, downloadUrl, name, label, kind }
        loading: false,
        error: null,

        async open(file) {
            this.close();
            this.current = file;
            this.error = null;
            document.body.classList.add('overflow-hidden');

            if (file.kind !== 'docx') return;

            this.loading = true;
            await this.$nextTick();
            try {
                const [{ SuperDoc }, res] = await Promise.all([
                    import('superdoc'),
                    fetch(file.url, { credentials: 'include' }),
                    import('superdoc/style.css'),
                ]);
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const blob = await res.blob();
                superdoc = new SuperDoc({
                    selector: this.$refs.docx,
                    document: new File([blob], file.name, { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }),
                    documentMode: 'viewing',
                    role: 'viewer',
                    viewing: { comments: true, trackedChanges: 'markup' },
                    contained: true,
                    telemetry: { enabled: false },
                    onReady: () => { this.loading = false; },
                    onException: ({ error }) => {
                        this.error = 'Could not show this Word file (' + (error?.message || 'unknown error') + ').';
                        this.loading = false;
                    },
                });
            } catch (e) {
                console.error(e);
                this.error = 'Could not show this Word file (' + (e?.message || 'unknown error') + ').';
                this.loading = false;
            }
        },

        close() {
            if (superdoc) {
                try { superdoc.destroy(); } catch (e) { /* already gone */ }
                superdoc = null;
            }
            this.current = null;
            this.loading = false;
            document.body.classList.remove('overflow-hidden');
        },
    };
}
