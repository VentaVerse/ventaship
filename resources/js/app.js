import './bootstrap';
import Alpine from 'alpinejs';
import { copyFeedback } from './copyFeedback';
import { copyText } from './copyText';
import { bulkSelect } from './bulkSelect';
import { customerSearch } from './customerSearch';
import { phCascade } from './phCascade';
import { phoneInput } from './phoneInput';
import { rowMenu } from './rowMenu';
import { shipmentForm } from './shipmentForm';

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

Alpine.data('shipmentForm', (config) => shipmentForm(config));

Alpine.data('phAddress', (config) => phCascade(config));

Alpine.data('phoneInput', () => phoneInput());

Alpine.data('rowMenu', () => rowMenu());

Alpine.data('customerSearch', (config) => customerSearch(config));

Alpine.data('bulkSelect', (config = {}) => bulkSelect(config));

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
    copied: 'idle',
    copyNote: '',
    copyTimer: null,
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
        // A key just issued: shown once on load, fetched (and audited) like any View.
        if (config.autoView) {
            this.view();
        }
    },

    destroy() {
        window.removeEventListener('pagehide', this.onPageHide);
        this.stopTimers();
        clearTimeout(this.copyTimer);
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
                const refused = (res.status === 429 || res.status === 422) && typeof body.message === 'string';
                this.error = refused ? body.message : 'Could not show it.';
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

    /**
     * Copies the full value; fetches it first (audited like View) when it is not on screen. A plain value
     * (config.value, nothing secret) is copied as it is.
     */
    async copy() {
        const plain = typeof config.value === 'string';
        if (this.busy || (!config.url && !plain)) {
            return;
        }
        this.busy = true;
        this.error = '';
        try {
            const value = plain ? config.value : this.state === 'viewing' ? this.$refs.input.value : await this.fetchValue();
            if (value === null) {
                return;
            }
            copyFeedback(this, (await copyText(value)) ? 'ok' : 'fail');
        } catch (e) {
            copyFeedback(this, 'fail');
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
