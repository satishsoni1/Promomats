{{--
    Comments version by version (MOM 24 Nov), newest version first: the platform's
    comments (general thread, PDF pins, video notes) made on that version, plus the
    comments embedded in that version's Word file. Expects $commentsByVersion,
    $document, $commentsLocked; $readOnly hides reply forms (agency view).
--}}
@php $readOnly = ($readOnly ?? false) || $commentsLocked; @endphp
@forelse ($commentsByVersion as $group)
    @php $v = $group['version']; @endphp
    <div class="mb-5 last:mb-0" x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }">
        <button type="button" @click="open = ! open" class="w-full flex items-center justify-between gap-2 py-1.5 border-b border-gray-200 text-left">
            <span class="text-xs font-semibold text-gray-700">
                {{ $v ? 'v' . $v->version_no : 'Earlier comments' }}
                @if ($v?->is_current)<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-medium">current</span>@endif
                <span class="font-normal text-gray-400">{{ $v ? '· ' . $v->original_filename . ' · ' . $v->created_at?->format('d M Y, H:i') : '' }}</span>
            </span>
            <span class="text-[11px] text-gray-400 shrink-0">{{ $group['comments']->count() + count($group['word_comments']) }} <span x-text="open ? '▾' : '▸'"></span></span>
        </button>

        <div x-show="open" x-cloak>
            @foreach ($group['comments'] as $comment)
                <div class="py-3 border-b border-gray-100 last:border-b-0 text-sm" id="comment-{{ $comment->id }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium text-gray-900 flex items-center gap-1.5"><x-avatar :name="$comment->author?->name" size="xs" />{{ $comment->author?->name }}
                            @if ($comment->isDraft())<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-sky-100 text-sky-700 font-medium">draft — only you</span>@endif
                            @if ($comment->page_number)<span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">p.{{ $comment->page_number }}</span>@endif
                            @if ($comment->timestamp_seconds !== null)<span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">@ {{ gmdate('i:s', (int) $comment->timestamp_seconds) }}</span>@endif
                            @if ($comment->resolved_at)<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700">resolved</span>@endif
                        </span>
                        <span class="text-gray-400 text-xs shrink-0" title="{{ $comment->created_at->format('d M Y, H:i') }} IST">{{ $comment->created_at->diffForHumans() }}</span>
                    </div>
                    @if ($comment->selected_text)
                        <p class="mt-1 text-xs text-gray-500 border-l-2 border-amber-300 pl-2 italic">“{{ \Illuminate\Support\Str::limit($comment->selected_text, 200) }}”</p>
                    @endif
                    <p class="mt-1.5 text-gray-700 whitespace-pre-line">{{ $comment->body }}</p>
                    @if ($comment->replacement_text)
                        <p class="mt-1 text-xs text-emerald-800 bg-emerald-50 rounded p-1.5"><strong>Suggested:</strong> {{ $comment->replacement_text }}</p>
                    @endif
                    @unless ($readOnly)
                        <button type="button" onclick="document.getElementById('reply-form-{{ $comment->id }}').classList.toggle('hidden')" class="mt-1 text-xs text-brand-600 hover:underline">Reply</button>
                        <form id="reply-form-{{ $comment->id }}" method="POST" action="{{ route('documents.comments.store', $document) }}" class="hidden mt-2 ml-4 space-y-2">
                            @csrf
                            <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                            <textarea name="body" rows="2" required placeholder="Write a reply…" class="block w-full text-sm border-gray-300 rounded-lg"></textarea>
                            <x-primary-button>Post Reply</x-primary-button>
                        </form>
                    @endunless

                    @foreach ($comment->replies as $reply)
                        <div class="mt-3 ml-4 pl-3 border-l-2 border-gray-200">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-gray-900 flex items-center gap-1.5"><x-avatar :name="$reply->author?->name" size="xs" />{{ $reply->author?->name }} @if ($reply->isDraft())<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-sky-100 text-sky-700 font-medium">draft — only you</span> @endif</span>
                                <span class="text-gray-400 text-xs shrink-0">{{ $reply->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="mt-1 text-gray-700 whitespace-pre-line">{{ $reply->body }}</p>
                        </div>
                    @endforeach
                </div>
            @endforeach

            @if (! empty($group['word_comments']))
                <div class="mt-2 rounded-lg bg-blue-50/50 border border-blue-100 p-3">
                    <div class="flex items-center justify-between gap-2 mb-1.5">
                        <p class="text-[11px] font-semibold text-blue-900 uppercase tracking-wide">In the Word file ({{ count($group['word_comments']) }})</p>
                        @if ($v)
                            <a href="{{ $v->downloadUrl() }}" class="text-[11px] text-blue-700 hover:underline">⬇ Download with comments</a>
                        @endif
                    </div>
                    @foreach ($group['word_comments'] as $wc)
                        <div class="py-2 border-t border-blue-100 first:border-t-0 text-sm {{ $wc['is_reply'] ? 'ml-4' : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-gray-900">{{ $wc['is_reply'] ? '↳ ' : '' }}{{ $wc['author'] }}
                                    @if ($wc['done'])<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700">resolved</span>@endif
                                </span>
                                @if ($wc['date'])
                                    <span class="text-gray-400 text-xs shrink-0">{{ \Illuminate\Support\Carbon::parse($wc['date'])->timezone(config('app.timezone'))->format('d M Y, H:i') }}</span>
                                @endif
                            </div>
                            @if ($wc['anchor'])
                                <p class="mt-0.5 text-xs text-gray-500 border-l-2 border-blue-300 pl-2 italic">“{{ $wc['anchor'] }}”</p>
                            @endif
                            <p class="mt-1 text-gray-700 whitespace-pre-line">{{ $wc['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@empty
    <p class="text-sm text-gray-400">No comments yet{{ $readOnly ? '.' : ' — be the first to add one.' }}</p>
@endforelse
