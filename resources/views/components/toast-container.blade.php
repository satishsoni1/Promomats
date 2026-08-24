{{--
    Global toast notifications - replaces the repeated inline "bg-green-50 border..."
    status banners that used to be hand-copied into ~27 individual views. Reads the
    same session('status') flash + validation $errors bag every page already sets,
    so no controller changes were needed anywhere for this to start working.
--}}
<div
    x-data="{
        toasts: [],
        add(type, message) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type, message });
            setTimeout(() => this.remove(id), 6000);
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
    }"
    x-init="
        @if (session('status')) add('success', @js(session('status'))); @endif
        @if ($errors->any())
            @foreach ($errors->all() as $error) add('error', @js($error)); @endforeach
        @endif
    "
    class="fixed top-4 right-4 z-[100] w-full max-w-sm space-y-2"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            :class="toast.type === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'"
            class="border rounded-xl shadow-lg shadow-gray-900/5 px-4 py-3 text-sm flex items-start gap-2.5"
        >
            <svg x-show="toast.type === 'success'" class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12.5l2.5 2.5 5-5.5M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" /></svg>
            <svg x-show="toast.type === 'error'" class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" /></svg>
            <span x-text="toast.message" class="flex-1 leading-snug"></span>
            <button @click="remove(toast.id)" class="shrink-0 opacity-50 hover:opacity-100" aria-label="Dismiss">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
        </div>
    </template>
</div>
