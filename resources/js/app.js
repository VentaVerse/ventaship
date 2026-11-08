import './bootstrap';
import Alpine from 'alpinejs';

Alpine.data('confirmDialog', () => ({
    open: false,
    title: '',
    confirm: 'Confirm',
    dismiss: 'Back',
    form: null,
    submitter: null,

    show(detail) {
        this.title = detail.title ?? '';
        this.confirm = detail.confirm ?? 'Confirm';
        this.dismiss = detail.dismiss ?? 'Back';
        this.form = detail.form ?? null;
        this.submitter = detail.submitter ?? null;
        this.open = true;
        this.$nextTick(() => this.$refs.dialog.focus());
    },

    close() {
        this.open = false;
        this.form = null;
        this.submitter = null;
    },

    submit() {
        const form = this.form ? document.getElementById(this.form) : null;
        const submitter = this.submitter ? document.getElementById(this.submitter) : null;
        this.close();
        if (form) {
            form.requestSubmit(submitter ?? undefined);
        }
    },
}));

Alpine.data('serviceSelect', (config) => ({
    account: config.account,
    service: config.service,

    get options() {
        return config.services[this.account] ?? [];
    },

    init() {
        this.$watch('account', () => {
            if (!this.options.some((o) => o.value === this.service)) {
                this.service = '';
            }
        });
    },
}));

Alpine.data('itemRows', (config) => ({
    rows: [],
    seq: 0,

    init() {
        this.rows = config.rows.map((row, n) => this.make(row, config.errors[n] ?? {}));
    },

    make(row, errors = {}) {
        return { key: ++this.seq, name: row.name, quantity: row.quantity, price: row.price, errors };
    },

    add() {
        this.rows.push(this.make({ name: '', quantity: '1', price: '' }));
    },

    remove(n) {
        if (this.rows.length > 1) {
            this.rows.splice(n, 1);
        }
    },
}));

const PH_LEVELS = ['state', 'city', 'district', 'barangay'];
const PH_LIST = { state: 'provinces', city: 'cities', district: 'districts', barangay: 'barangays' };

// Province > city > (Manila district >) barangay. Children load when a parent changes and everything below it is
// cleared. A level whose saved text matched nothing stays editable text until "Choose from list" is pressed.
Alpine.data('phAddress', (config) => ({
    sel: config.sel,
    free: config.free,
    text: config.text,
    lists: config.lists,
    postcode: config.postcode,
    seq: 0,

    options(level) {
        return this.lists[PH_LIST[level]] ?? [];
    },

    row(level) {
        return this.options(level).find((o) => o.code === this.sel[level]) ?? null;
    },

    hasDistricts() {
        return !this.free.city && this.sel.city !== '' && this.lists.districts.length > 0;
    },

    show(level) {
        return level !== 'district' || this.hasDistricts() || this.free.district;
    },

    isFree(level) {
        return this.free[level];
    },

    enabled(level) {
        if (level === 'state') {
            return true;
        }
        if (level === 'city') {
            return this.sel.state !== '';
        }
        if (level === 'district') {
            return this.sel.city !== '';
        }
        return this.sel.city !== '' && (!this.hasDistricts() || this.sel.district !== '');
    },

    canChoose(level) {
        const above = PH_LEVELS.slice(0, PH_LEVELS.indexOf(level));

        return this.free[level] && !above.some((l) => this.free[l]);
    },

    name(level) {
        if (level === 'district' && !this.show('district')) {
            return '';
        }
        if (this.free[level]) {
            return this.text[level].trim();
        }
        return this.row(level)?.name ?? '';
    },

    code(level) {
        return this.free[level] ? '' : this.sel[level];
    },

    below(level) {
        return PH_LEVELS.slice(PH_LEVELS.indexOf(level) + 1);
    },

    clear(levels) {
        levels.forEach((l) => {
            this.sel[l] = '';
            this.free[l] = false;
            this.text[l] = '';
            this.lists[PH_LIST[l]] = l === 'state' ? this.lists.provinces : [];
        });
    },

    async load(level, parent) {
        const ticket = ++this.seq;
        try {
            const res = await fetch(`${config.url}/${PH_LIST[level]}?parent=${encodeURIComponent(parent)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = res.ok ? await res.json() : [];
            if (ticket === this.seq) {
                this.lists[PH_LIST[level]] = Array.isArray(data) ? data : [];
            }
            return ticket === this.seq;
        } catch (e) {
            return false;
        }
    },

    async changed(level) {
        this.clear(this.below(level));
        const value = this.sel[level];
        if (value === '') {
            return;
        }
        if (level === 'state') {
            await this.load('city', value);
        } else if (level === 'city') {
            const zip = this.row('city')?.zip ?? '';
            if (zip !== '') {
                this.postcode = zip;
            }
            await this.load('district', value);
            if (this.lists.districts.length === 0) {
                await this.load('barangay', value);
            }
        } else if (level === 'district') {
            await this.load('barangay', value);
        }
    },

    async choose(level) {
        this.clear(this.below(level));
        this.sel[level] = '';
        this.free[level] = false;
        this.text[level] = '';
        this.$nextTick(() => this.$root.querySelector(`select[id$="-${level}"]`)?.focus());
        if (level === 'city' && this.sel.state !== '') {
            await this.load('city', this.sel.state);
        } else if (level === 'district' && this.sel.city !== '') {
            await this.load('district', this.sel.city);
        } else if (level === 'barangay' && this.sel.city !== '') {
            await this.load('barangay', this.sel.district || this.sel.city);
        }
    },
}));

Alpine.data('bulkSelect', () => ({
    count: 0,

    sync() {
        this.count = this.$root.querySelectorAll('input[name="ids[]"]:checked').length;
    },
}));

Alpine.data('fulfillmentPoll', (config) => ({
    counts: config.counts,
    timer: null,

    init() {
        this.timer = setInterval(() => this.poll(), 20000);
    },

    destroy() {
        clearInterval(this.timer);
    },

    async poll() {
        if (document.visibilityState !== 'visible') {
            return;
        }
        const rows = [...this.$root.querySelectorAll('[data-id]')].slice(0, 100);
        const params = new URLSearchParams({ ...config.query, ids: rows.map((r) => r.dataset.id).join(',') });
        try {
            const res = await fetch(`${config.url}?${params}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                return;
            }
            const data = await res.json();
            this.counts = data.counts;
            const statuses = data.statuses && typeof data.statuses === 'object' ? data.statuses : {};
            const changed = rows.some((r) => {
                const now = statuses[r.dataset.id];

                return typeof now === 'string' && now !== '' && now !== r.dataset.status;
            });
            if (changed && !this.$root.querySelector('input[name="ids[]"]:checked')) {
                window.location.reload();
            }
        } catch (e) {
            // next tick retries
        }
    },
}));

const CREDENTIAL_SHOW_MS = 30000;

// A stored courier secret: masked (dots plus last 4), viewing (fetched on demand, re-masked after 30 s) or editing
// (empty, and the only state in which the input has a name). The fetched value lives only in the input's value.
Alpine.data('credentialField', (config) => ({
    state: config.isSet ? 'masked' : 'unset',
    name: config.name,
    busy: false,
    error: '',
    left: 0,
    timer: null,
    ticker: null,

    get tick() {
        return `0:${String(this.left).padStart(2, '0')}`;
    },

    init() {
        // A shown value must not survive in a page restored from the back/forward cache.
        this.onPageHide = () => { if (this.state === 'viewing') { this.rest(); } };
        window.addEventListener('pagehide', this.onPageHide);
    },

    destroy() {
        window.removeEventListener('pagehide', this.onPageHide);
        this.stopTimers();
    },

    stopTimers() {
        clearTimeout(this.timer);
        clearInterval(this.ticker);
        this.timer = null;
        this.ticker = null;
    },

    announce(text) {
        this.$dispatch('credential-status', text);
    },

    rest() {
        this.stopTimers();
        this.$refs.input.value = config.mask;
        this.state = config.isSet ? 'masked' : 'unset';
    },

    async fetchValue() {
        try {
            const res = await fetch(config.url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok || typeof body.value !== 'string') {
                this.error = res.status === 429 && body.message ? body.message : 'Could not show it.';
                return null;
            }
            return body.value;
        } catch (e) {
            this.error = 'Could not show it.';
            return null;
        }
    },

    async view() {
        if (this.busy || this.state !== 'masked' || !config.url) {
            return;
        }
        this.busy = true;
        this.error = '';
        try {
            const value = await this.fetchValue();
            if (value === null || this.state !== 'masked') {
                return;
            }
            this.$refs.input.value = value;
            this.state = 'viewing';
            this.left = CREDENTIAL_SHOW_MS / 1000;
            this.timer = setTimeout(() => this.hide(), CREDENTIAL_SHOW_MS);
            this.ticker = setInterval(() => { this.left = Math.max(0, this.left - 1); }, 1000);
            this.announce('Shown for 30 seconds');
        } finally {
            this.busy = false;
        }
    },

    /** Copies the full value; fetches it first (audited like View) when it is not on screen. */
    async copy() {
        if (this.busy || !config.url) {
            return;
        }
        this.busy = true;
        this.error = '';
        try {
            const value = this.state === 'viewing' ? this.$refs.input.value : await this.fetchValue();
            if (value === null) {
                return;
            }
            await navigator.clipboard.writeText(value);
            this.announce('Copied');
        } catch (e) {
            this.error = 'Could not copy it.';
        } finally {
            this.busy = false;
        }
    },

    hide() {
        if (this.state !== 'viewing') {
            return;
        }
        this.rest();
        this.announce('Hidden');
    },

    edit() {
        const wasViewing = this.state === 'viewing';
        this.stopTimers();
        this.error = '';
        this.$refs.input.value = '';
        this.state = 'editing';
        if (wasViewing) {
            this.announce('Hidden');
        }
        this.$nextTick(() => this.$refs.input.focus());
    },

    cancel() {
        this.rest();
    },
}));

window.Alpine = Alpine;
Alpine.start();
