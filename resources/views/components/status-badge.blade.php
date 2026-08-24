@props(['status'])
@php
    $styles = [
        'draft' => 'bg-gray-100 text-gray-600 ring-gray-500/10',
        'in_review' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'approved_with_changes_pending' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
        'rejected' => 'bg-red-50 text-red-700 ring-red-600/20',
        'approved' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'approved_for_production' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'approved_for_distribution' => 'bg-teal-50 text-teal-700 ring-teal-600/20',
        'pending_expiration' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'expired' => 'bg-red-50 text-red-700 ring-red-600/20',
        'superseded' => 'bg-slate-100 text-slate-600 ring-slate-500/10',
        'obsolete' => 'bg-slate-100 text-slate-500 ring-slate-500/10',
        'archived' => 'bg-slate-100 text-slate-500 ring-slate-500/10',
    ];
    $classes = $styles[$status] ?? 'bg-gray-100 text-gray-600 ring-gray-500/10';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium ring-1 ring-inset whitespace-nowrap $classes"]) }}>
    <span class="w-1.5 h-1.5 rounded-full bg-current opacity-70 shrink-0"></span>
    {{ $slot }}
</span>
