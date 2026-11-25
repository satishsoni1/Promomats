{{--
    Searchable dropdown - see resources/js/search-select.js.

    Live options from the surrounding Alpine scope (value bound to `model`):
        <x-search-select options="skuOptions" model="skuId" name="sku_id" placeholder="Search SKU…" />
    Enhance a server-rendered <select> (it stays the form field):
        <x-search-select placeholder="Reassign to…"><select name="user_id">…</select></x-search-select>
    "+ Add someone" picker that clears after each pick:
        <x-search-select options="allUsers" clear-on-pick on-pick="addExtra(stage, id)" placeholder="+ Add someone else…" />
--}}
@props([
    'options' => null,      // JS expression evaluating to [{id, name, hint?}]
    'model' => null,        // JS variable in the parent scope to bind the value to
    'name' => null,         // hidden input name (options mode)
    'placeholder' => 'Search…',
    'clearOnPick' => false,
    'onPick' => null,       // JS statement, `id` in scope
    'onChange' => null,     // JS statement, `id` in scope
    'disabled' => null,     // JS expression
    'required' => false,
    'size' => 'md',         // md | sm
])
@php
    $inputSize = $size === 'sm' ? 'text-xs py-1 pl-2 pr-7' : 'text-sm py-2 pl-3 pr-8';
@endphp
<div x-data="searchSelect({
        options: {{ $options ? '() => (' . $options . ')' : 'null' }},
        placeholder: @js($placeholder),
        clearOnPick: @js((bool) $clearOnPick),
        onPick: {{ $onPick ? '(id) => { ' . $onPick . ' }' : 'null' }},
        onChange: {{ $onChange ? '(id) => { ' . $onChange . ' }' : 'null' }},
        disabled: {{ $disabled ? '() => (' . $disabled . ')' : 'null' }},
     })"
     @if ($model) x-modelable="value" x-model="{{ $model }}" @endif
     @click.outside="close()"
     {{ $attributes->merge(['class' => 'relative [&>select]:block [&>select]:w-full [&>select]:border-gray-300 [&>select]:rounded-md [&>select]:shadow-sm [&>select]:focus:border-brand-500 [&>select]:focus:ring-brand-500 ' . ($size === 'sm' ? '[&>select]:text-xs [&>select]:py-1' : '[&>select]:text-sm [&>select]:py-2')]) }}>
    {{ $slot }}
    @if ($name)
        <input type="hidden" name="{{ $name }}" :value="value">
    @endif
    {{-- Shown once the script has started; until then (or if it can't load) the
         plain <select> in the slot stays visible and usable. --}}
    <div x-show="ready" style="display: none">
    <div class="relative">
        <input type="text" x-ref="input" autocomplete="off"
               x-model="query"
               :placeholder="selectedLabel || placeholder"
               :disabled="disabled"
               :required="({{ $required ? 'true' : 'nativeRequired' }}) && ! value"
               @focus="openList()" @click="openList()" @input="openList()"
               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
               @keydown.enter.prevent="pickActive()" @keydown.escape.stop="close()" @keydown.tab="close()"
               :class="selectedLabel ? 'placeholder:text-gray-900' : 'placeholder:text-gray-400'"
               class="block w-full {{ $inputSize }} border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm disabled:bg-gray-50 disabled:cursor-not-allowed">
        <button type="button" x-show="selectedLabel && ! disabled" x-cloak @click="clear()" class="absolute inset-y-0 right-6 px-1 text-gray-400 hover:text-gray-600 text-sm" title="Clear">&times;</button>
        <svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" /></svg>
    </div>
    <ul x-show="open" x-cloak x-ref="list"
        class="absolute z-40 mt-1 w-full max-h-60 overflow-auto rounded-md bg-white shadow-lg ring-1 ring-gray-900/10 py-1 text-sm">
        <template x-for="(item, i) in items" :key="item.id">
            <li @mousedown.prevent="pick(item)" @mouseenter="active = i"
                :class="i === active ? 'bg-brand-50 text-brand-800' : 'text-gray-700'"
                class="px-3 py-1.5 cursor-pointer flex items-center justify-between gap-2">
                <span x-text="item.name" class="truncate"></span>
                <span x-show="item.hint" x-text="item.hint" class="text-[11px] text-gray-400 shrink-0"></span>
            </li>
        </template>
        <li x-show="! items.length" class="px-3 py-1.5 text-gray-400">No matches</li>
        <li x-show="moreCount > 0" class="px-3 py-1 text-[11px] text-gray-400 border-t border-gray-100" x-text="'Type to search ' + moreCount + ' more…'"></li>
    </ul>
    </div>
</div>
