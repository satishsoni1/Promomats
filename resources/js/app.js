import './bootstrap';

import Alpine from 'alpinejs';
import pdfAnnotationViewer from './pdf-viewer';
import videoAnnotationPlayer from './video-annotation';
import pdfContentEditor from './pdf-editor';

window.Alpine = Alpine;

Alpine.data('pdfAnnotationViewer', pdfAnnotationViewer);
Alpine.data('videoAnnotationPlayer', videoAnnotationPlayer);
Alpine.data('pdfContentEditor', pdfContentEditor);

Alpine.start();
