/**
 * Searchable dropdown (see resources/views/components/search-select.blade.php).
 * Type to filter, arrow keys + Enter to pick, Esc to close.
 *
 * Two ways to feed it:
 *  - options: () => [{ id, name, hint? }]   a live list from the surrounding
 *    Alpine scope (e.g. the SKUs of the selected brand); the value is two-way
 *    bound with x-modelable / x-model and posted through a hidden input.
 *  - a native <select> in the slot              the select stays the form field
 *    (hidden); picking sets it and fires "change", so existing onchange handlers
 *    (e.g. auto-submit) keep working.
 *
 * clearOnPick + onPick: a "+ add someone" picker that hands each pick to a
 * callback and clears itself instead of holding a value.
 */
const MAX_RESULTS = 60;

export default function searchSelect(cfg = {}) {
    return {
        open: false,
        query: '',
        value: cfg.value ?? '',
        active: 0,
        nativeOptions: [],
        select: null,
        // A hidden required <select> would block the form without showing why,
        // so "required" moves to the visible search box.
        nativeRequired: false,
        placeholder: cfg.placeholder || 'Search…',

        init() {
            this.select = this.$el.querySelector('select');
            if (this.select) {
                this.nativeOptions = Array.from(this.select.options)
                    .filter((o) => o.value !== '')
                    .map((o) => ({ id: o.value, name: o.textContent.trim() }));
                if (!cfg.clearOnPick) this.value = this.select.value;
                this.nativeRequired = this.select.required;
                this.select.required = false;
                this.select.classList.add('hidden');
                this.select.tabIndex = -1;
            }
        },

        get source() {
            const list = cfg.options ? cfg.options() || [] : this.nativeOptions;
            return list.map((o) => ({ ...o, id: String(o.id) }));
        },

        get items() {
            const q = this.query.trim().toLowerCase();
            const terms = q.split(/\s+/).filter(Boolean);
            const matches = terms.length
                ? this.source.filter((o) => {
                      const hay = (o.name + ' ' + (o.hint || '')).toLowerCase();
                      return terms.every((t) => hay.includes(t));
                  })
                : this.source;
            return matches.slice(0, MAX_RESULTS);
        },

        get moreCount() {
            const q = this.query.trim();
            return q ? 0 : Math.max(0, this.source.length - MAX_RESULTS);
        },

        get selectedLabel() {
            if (cfg.clearOnPick || this.value === '' || this.value === null) return '';
            return this.source.find((o) => o.id === String(this.value))?.name || '';
        },

        get disabled() {
            return cfg.disabled ? !!cfg.disabled() : false;
        },

        openList() {
            if (this.disabled) return;
            this.open = true;
            this.active = 0;
        },

        close() {
            this.open = false;
            this.query = '';
        },

        move(step) {
            if (!this.open) return this.openList();
            const n = this.items.length;
            if (n) this.active = (this.active + step + n) % n;
            this.$nextTick(() => this.$refs.list?.children[this.active]?.scrollIntoView({ block: 'nearest' }));
        },

        pickActive() {
            const item = this.items[this.active];
            if (this.open && item) this.pick(item);
        },

        pick(item) {
            if (cfg.clearOnPick) {
                cfg.onPick?.(item.id, item);
                this.close();
                this.$refs.input?.focus();
                return;
            }
            this.setValue(item.id);
            this.close();
        },

        clear() {
            this.setValue('');
            this.close();
        },

        setValue(id) {
            this.value = id;
            if (this.select) {
                this.select.value = id;
                this.select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            cfg.onChange?.(id);
        },
    };
}
