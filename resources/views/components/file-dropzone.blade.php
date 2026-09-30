@props([
    'name' => 'file',
    'id' => null,
    'required' => false,
    'accept' => null,
    'hint' => null,
])
{{--
    Drag-and-drop replacement for a bare <input type="file">. The real file input
    stays inside the form (so a normal multipart POST, server-side validation and
    `required` all behave exactly as before) - it's just stretched invisibly over
    the drop area, so a click anywhere opens the native picker, and a dropped file
    is copied onto that same input via DataTransfer.
--}}
<div x-data="{
        dragging: false,
        fileName: null,
        fileSize: null,
        sync() {
            const f = this.$refs.input.files[0];
            this.fileName = f ? f.name : null;
            this.fileSize = f ? (f.size >= 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB') : null;
        },
        drop(e) {
            this.dragging = false;
            const f = e.dataTransfer?.files?.[0];
            if (!f) return;
            const dt = new DataTransfer();
            dt.items.add(f);
            this.$refs.input.files = dt.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },
        clear() {
            this.$refs.input.value = '';
            this.sync();
        },
     }"
     data-file-dropzone
     @dragenter.prevent="dragging = true"
     @dragover.prevent="dragging = true"
     @dragleave.prevent="if (! $el.contains($event.relatedTarget)) dragging = false"
     @drop.prevent="drop($event)"
     {{ $attributes->merge(['class' => 'relative']) }}>
    <div :class="dragging ? 'border-brand-500 bg-brand-50' : (fileName ? 'border-gray-300 bg-white' : 'border-gray-300 bg-white hover:border-brand-400 hover:bg-gray-50')"
         class="flex flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-5 text-center transition-colors">
        <template x-if="! fileName">
            <div class="text-sm text-gray-600">
                <svg class="mx-auto mb-1 h-7 w-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                </svg>
                <span x-show="! dragging"><span class="font-medium text-brand-600">Drag &amp; drop a file here</span> or click to browse</span>
                <span x-show="dragging" x-cloak class="font-medium text-brand-700">Drop to attach</span>
            </div>
        </template>
        <template x-if="fileName">
            <div class="flex max-w-full items-center gap-2 text-sm">
                <span class="text-gray-400" aria-hidden="true">📄</span>
                <span class="truncate font-medium text-gray-900" x-text="fileName"></span>
                <span class="shrink-0 text-xs text-gray-400" x-text="fileSize"></span>
                {{-- z-10 lifts the button above the stretched input so it's clickable --}}
                <button type="button" @click.stop.prevent="clear()" class="relative z-10 shrink-0 text-gray-400 hover:text-red-600" title="Remove file">&times;</button>
            </div>
        </template>
        @if ($hint)
            <p class="text-xs text-gray-400">{{ $hint }}</p>
        @endif
    </div>
    <input type="file"
           x-ref="input"
           name="{{ $name }}"
           @if ($id) id="{{ $id }}" @endif
           @if ($accept) accept="{{ $accept }}" @endif
           @if ($required) required @endif
           @change="sync()"
           class="absolute inset-0 h-full w-full cursor-pointer opacity-0">
</div>
