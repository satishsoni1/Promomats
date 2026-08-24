@php
    $version = $document->currentVersion;
@endphp
<div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4"
     x-data="videoAnnotationPlayer({
         videoUrl: '{{ $version->viewUrl() }}',
         csrfToken: '{{ csrf_token() }}',
         storeUrl: '{{ route('documents.comments.store', $document) }}',
         initialAnnotations: {{ \Illuminate\Support\Js::from($videoAnnotations) }},
     })"
     x-init="init()">

    <div class="flex items-center justify-between mb-3">
        <h3 class="text-lg font-semibold text-gray-900">Preview &amp; Notes</h3>
        <button type="button" @click="addPinAtCurrentTime()"
                class="text-xs px-2.5 py-1.5 bg-accent-500 text-white rounded-md hover:bg-accent-600">
            + Add note at <span x-text="formatTime(currentTime)"></span>
        </button>
    </div>

    <video x-ref="video" controls preload="metadata" class="w-full rounded-lg bg-black" style="max-height: 60vh;">
        <source src="{{ $version->viewUrl() }}" type="{{ $version->mime_type }}">
        Your browser doesn't support inline video playback. <a href="{{ $version->downloadUrl() }}" class="text-brand-600 hover:underline">Download it instead</a>.
    </video>

    <!-- Timeline with pin markers -->
    <div class="relative h-6 mt-2 mb-1" x-show="duration > 0" x-cloak>
        <div class="absolute inset-x-0 top-1/2 -translate-y-1/2 h-1 bg-gray-100 rounded-full"></div>
        <template x-for="pin in annotations" :key="pin.id">
            <button type="button" @click="seekTo(pin.timestamp_seconds); openPin = (openPin === pin.id ? null : pin.id)"
                    class="absolute -translate-x-1/2 top-1/2 -translate-y-1/2 w-3 h-3 rounded-full bg-accent-500 ring-2 ring-white shadow hover:scale-125 transition-transform"
                    :style="`left:${pinPercent(pin.timestamp_seconds)}%;`"
                    :title="formatTime(pin.timestamp_seconds) + ' — ' + pin.body"></button>
        </template>
    </div>

    <div x-show="pendingPin" x-cloak class="mt-3 bg-orange-50 border border-orange-100 rounded-md p-3">
        <p class="text-xs text-gray-600 mb-2">Note at <strong x-text="formatTime(pendingPin ? pendingPin.time : 0)"></strong>:</p>
        <textarea x-model="newBody" rows="2" placeholder="What's happening at this moment?" class="w-full text-sm border-gray-300 rounded-md mb-2"></textarea>
        <div class="flex justify-end gap-2">
            <button type="button" @click="cancelPending()" class="text-xs text-gray-500">Cancel</button>
            <button type="button" @click="submitPin()" :disabled="posting" class="text-xs text-white bg-brand-600 px-3 py-1.5 rounded-md disabled:opacity-40">Post Note</button>
        </div>
    </div>

    <!-- Chronological list of video notes -->
    <div class="mt-4 pt-4 border-t border-gray-100" x-show="annotations.length > 0" x-cloak>
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Video Notes (<span x-text="annotations.length"></span>)</p>
        <template x-for="pin in annotations" :key="pin.id">
            <div class="py-2 border-t border-gray-50 first:border-t-0 text-sm">
                <div class="flex items-start gap-2">
                    <button type="button" @click="seekTo(pin.timestamp_seconds)"
                            class="shrink-0 font-mono text-xs px-1.5 py-0.5 bg-brand-50 text-brand-700 rounded hover:bg-brand-100" x-text="formatTime(pin.timestamp_seconds)"></button>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-gray-900 text-xs" x-text="pin.author.name"></span>
                            <span class="text-gray-400 text-xs" x-text="pin.created_at"></span>
                        </div>
                        <p class="text-gray-700 whitespace-pre-line" x-text="pin.body"></p>
                        <button type="button" @click="openPin = (openPin === pin.id ? null : pin.id)" class="mt-0.5 text-xs text-brand-600 hover:underline">Reply</button>

                        <template x-for="reply in pin.replies" :key="reply.id">
                            <div class="mt-1.5 ml-2 pl-2 border-l-2 border-gray-100">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-gray-800 text-xs" x-text="reply.author.name"></span>
                                    <span class="text-gray-400 text-xs" x-text="reply.created_at"></span>
                                </div>
                                <p class="text-gray-600 text-xs whitespace-pre-line" x-text="reply.body"></p>
                            </div>
                        </template>

                        <div x-show="openPin === pin.id" x-cloak class="mt-1.5 flex gap-1">
                            <input type="text" x-model="replyBody" placeholder="Reply…" @keydown.enter="submitReply(pin)"
                                   class="flex-1 text-xs border-gray-300 rounded">
                            <button type="button" @click="submitReply(pin)" :disabled="posting" class="text-xs text-brand-600 font-medium disabled:opacity-40">Send</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
