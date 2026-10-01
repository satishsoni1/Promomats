import './bootstrap';

import Alpine from 'alpinejs';
import pdfAnnotationViewer from './pdf-viewer';
import videoAnnotationPlayer from './video-annotation';
import pdfContentEditor from './pdf-editor';
import wordReviewEditor from './word-editor';
import versionPreview from './version-preview';

window.Alpine = Alpine;

// A file dropped anywhere outside an upload drop zone would otherwise make the
// browser navigate away to open it, throwing away whatever form was half filled.
['dragover', 'drop'].forEach((type) => {
    window.addEventListener(type, (e) => {
        if (e.dataTransfer?.types?.includes('Files') && !e.target.closest?.('[data-file-dropzone]')) {
            e.preventDefault();
            if (type === 'dragover') e.dataTransfer.dropEffect = 'none';
        }
    });
});

Alpine.data('pdfAnnotationViewer', pdfAnnotationViewer);
Alpine.data('videoAnnotationPlayer', videoAnnotationPlayer);
Alpine.data('pdfContentEditor', pdfContentEditor);
Alpine.data('wordReviewEditor', wordReviewEditor);
Alpine.data('versionPreview', versionPreview);

Alpine.start();
