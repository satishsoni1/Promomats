@props(['name' => '', 'size' => 'sm'])
@php
    $initials = collect(explode(' ', trim((string) $name)))->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    $initials = strtoupper($initials ?: '?');
    $sizes = [
        'xs' => 'w-5 h-5 text-[9px]',
        'sm' => 'w-7 h-7 text-[11px]',
        'md' => 'w-9 h-9 text-xs',
        'lg' => 'w-11 h-11 text-sm',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['sm'];
    // Deterministic-but-varied color per name so avatars are easy to tell apart at a glance.
    $palette = ['bg-brand-100 text-brand-700', 'bg-accent-100 text-accent-700', 'bg-purple-100 text-purple-700', 'bg-blue-100 text-blue-700', 'bg-rose-100 text-rose-700', 'bg-emerald-100 text-emerald-700'];
    $color = $palette[crc32($name ?: '?') % count($palette)];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-full font-semibold shrink-0 $sizeClass $color"]) }}>
    {{ $initials }}
</span>
