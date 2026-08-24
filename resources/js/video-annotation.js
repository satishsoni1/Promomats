/**
 * Alpine.js component powering the in-page video viewer with timestamp-anchored
 * comment pins (REQ-2.1) - documents/partials/video-viewer.blade.php. Same pin/reply
 * interaction pattern as the PDF annotation viewer (pdf-viewer.js), but anchored to a
 * moment in time on the timeline instead of an x/y spot on a page - no pdf.js
 * private-field Proxy concerns here, since HTMLVideoElement is a plain DOM element.
 */
export default function videoAnnotationPlayer({ videoUrl, csrfToken, storeUrl, initialAnnotations }) {
    return {
        duration: 0,
        currentTime: 0,
        annotations: (initialAnnotations || []).slice().sort((a, b) => a.timestamp_seconds - b.timestamp_seconds),
        pendingPin: null, // { time }
        newBody: '',
        openPin: null,
        replyBody: '',
        posting: false,

        init() {
            const video = this.$refs.video;
            video.addEventListener('loadedmetadata', () => {
                this.duration = video.duration || 0;
            });
            video.addEventListener('timeupdate', () => {
                this.currentTime = video.currentTime;
            });
        },

        formatTime(seconds) {
            seconds = Math.max(0, Math.floor(seconds || 0));
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
        },

        pinPercent(seconds) {
            if (!this.duration) return 0;
            return Math.min(100, Math.max(0, (seconds / this.duration) * 100));
        },

        seekTo(seconds) {
            const video = this.$refs.video;
            video.currentTime = seconds;
            this.openPin = null;
        },

        addPinAtCurrentTime() {
            const video = this.$refs.video;
            video.pause();
            this.pendingPin = { time: video.currentTime };
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
                    timestamp_seconds: this.pendingPin.time,
                });
                this.annotations.push({ ...comment, replies: [] });
                this.annotations.sort((a, b) => a.timestamp_seconds - b.timestamp_seconds);
                this.pendingPin = null;
                this.newBody = '';
            } catch (e) {
                alert('Could not post the note. Please try again.');
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
    };
}
