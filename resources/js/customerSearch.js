import { nationalDigits } from './phoneInput.js';
// The Add / Edit shipment form's customer search: a combobox over GET /customers/search. The rows are the matches
// plus a last "New customer" row. Picking a customer fills the recipient's name, phone and street, and sends the
// address to the recipient PSGC cascade (phCascade.fill) through the `ph-fill` window event. The pick then shows as a
// box in place of the search: × (or "New customer") clears the pick and only the fields it filled that are still as
// it filled them. Editing the name or the phone unlinks the saved customer, so a saved record is never changed by a
// shipment it no longer describes.
const DEBOUNCE_MS = 250;
const MIN_CHARS = 2;
const FIELDS = ['first_name', 'last_name', 'phone', 'address_1'];

/** The next highlighted row for a key, wrapping; -1 when there are no rows. */
export function moveActive(active, total, key) {
    if (total <= 0) {
        return -1;
    }
    if (key === 'Home') {
        return 0;
    }
    if (key === 'End') {
        return total - 1;
    }
    if (key === 'ArrowUp') {
        return active <= 0 ? total - 1 : active - 1;
    }

    return active >= total - 1 ? 0 : active + 1;
}

/** A local mobile number as people write it: 0917 123 4567. */
export function phoneText(phone) {
    const m = String(phone ?? '').match(/^(\d{4})(\d{3})(\d{4})$/);

    return m ? `${m[1]} ${m[2]} ${m[3]}` : String(phone ?? '');
}

/** The recipient text fields a customer fills; the form has one street field, so the two lines are joined. */
export function recipientValues(address) {
    const text = (v) => (typeof v === 'string' ? v.trim() : '');
    const street = [text(address.address_1), text(address.address_2)].filter((p) => p !== '').join(', ');

    return { first_name: text(address.first_name), last_name: text(address.last_name), phone: text(address.phone), address_1: street };
}

/** "City, Province" for a saved address. */
export function placeText(address) {
    const text = (v) => (typeof v === 'string' ? v.trim() : '');

    return [text(address?.city), text(address?.state)].filter((p) => p !== '').join(', ');
}

/** True when a field still holds what the pick put there (the phone compared by its digits). */
export function untouched(name, current, filled) {
    if (name === 'phone') {
        return nationalDigits(current) === nationalDigits(filled);
    }

    return String(current ?? '').trim() === String(filled ?? '').trim();
}

const same = (a, b) => String(a ?? '').trim().toLowerCase() === String(b ?? '').trim().toLowerCase();

const digits = (v) => nationalDigits(v);

const byId = (id) => (typeof document === 'undefined' ? null : document.getElementById(id));

export function customerSearch(config) {
    return {
        q: '',
        customerId: config.customerId ?? '',
        picked: config.picked && config.customerId ? config.picked : null,
        filled: config.picked && config.customerId ? recipientValues(config.picked.address ?? {}) : {},
        searching: false,
        results: [],
        open: false,
        active: -1,
        status: '',
        seq: 0,
        timer: null,

        get total() {
            return this.results.length + 1;
        },

        get activeId() {
            return this.open && this.active >= 0 ? this.optionId(this.active) : null;
        },

        meta(customer) {
            return [phoneText(customer.phone), customer.summary].filter(Boolean).join(' · ');
        },

        get pickedMeta() {
            return this.picked ? [phoneText(this.picked.phone), placeText(this.picked.address)].filter(Boolean).join(' · ') : '';
        },

        // The pick box was pressed (or typed into): the search comes back, the pick stays until another is chosen.
        change(e) {
            if (e && (e.key?.length !== 1 || e.key === ' ' || e.ctrlKey || e.metaKey || e.altKey)) {
                return;
            }
            e?.preventDefault?.();
            this.searching = true;
            this.q = e ? e.key : '';
            this.$nextTick?.(() => {
                byId('f-customer-search')?.focus();
                if (this.q !== '') {
                    this.input();
                }
            });
        },

        // Leaving the search empty with a pick in place shows the pick again.
        blurred() {
            if (this.picked && this.q.trim() === '') {
                this.searching = false;
                this.close();
            }
        },

        optionId(n) {
            return `f-customer-opt-${n}`;
        },

        input() {
            clearTimeout(this.timer);
            const q = this.q.trim();
            if (q.length < MIN_CHARS) {
                this.seq++;
                this.close();
                return;
            }
            this.timer = setTimeout(() => this.search(q), DEBOUNCE_MS);
        },

        async search(q) {
            const seq = ++this.seq;
            let found = [];
            try {
                const res = await fetch(`${config.url}?${new URLSearchParams({ q })}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const body = res.ok ? await res.json() : {};
                found = Array.isArray(body.customers) ? body.customers : [];
            } catch (e) {
                found = [];
            }
            if (seq !== this.seq) {
                return;
            }
            this.results = found;
            this.active = -1;
            this.open = true;
            this.status = `${found.length} ${found.length === 1 ? 'customer' : 'customers'}`;
        },

        close() {
            this.open = false;
            this.active = -1;
        },

        key(e) {
            if (e.key === 'Enter') {
                // The search box sits inside the booking form: Enter here must never submit it.
                e.preventDefault();
                if (this.open && this.active >= 0) {
                    this.pick(this.active);
                }
                return;
            }
            if (e.key === 'Escape') {
                if (this.open) {
                    e.preventDefault?.();
                    this.close();
                } else if (this.q !== '') {
                    e.preventDefault?.();
                    this.q = '';
                    this.input();
                }
                return;
            }
            if (e.key === 'Tab') {
                this.close();
                return;
            }
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key) && this.open) {
                e.preventDefault();
                this.active = moveActive(this.active, this.total, e.key);
                this.$nextTick?.(() => byId(this.optionId(this.active))?.scrollIntoView({ block: 'nearest' }));
            }
        },

        pick(n) {
            const customer = this.results[n];
            this.close();
            this.q = '';
            this.seq++;
            if (customer) {
                this.customerId = String(customer.public_id ?? '');
                this.picked = customer;
                this.filled = recipientValues(customer.address ?? {});
                this.searching = false;
                this.fill(customer);
                this.status = `${customer.name} filled`;
                this.$nextTick?.(() => byId('f-customer-picked')?.focus());
            } else {
                this.customerId = '';
                this.clearCustomer();
            }
        },

        // Typing another name or phone after picking a customer makes it a new customer again (and brings back the
        // switch). The fill's own input events are not trusted, so they never unlink.
        edited(e) {
            const match = e?.isTrusted ? String(e.target?.name ?? '').match(/^recipient\[(first_name|last_name|phone)\]$/) : null;
            if (match && this.customerId !== '' && !untouched(match[1], e.target.value, this.filled[match[1]])) {
                this.unlink();
            }
        },

        unlink() {
            this.customerId = '';
            this.picked = null;
            this.filled = {};
            this.searching = false;
        },

        field(name) {
            return typeof document === 'undefined' ? null : document.querySelector(`input[name="recipient[${name}]"]`);
        },

        set(name, value) {
            const el = this.field(name);
            if (el) {
                el.value = value;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },

        fill(customer) {
            const values = recipientValues(customer.address ?? {});
            FIELDS.forEach((f) => this.set(f, values[f]));
            window.dispatchEvent(new CustomEvent('ph-fill', { detail: { prefix: 'recipient', address: customer.address ?? {} } }));
        },

        // × on the pick box: the pick and what it filled go; the search takes focus for the next one.
        clearPick() {
            this.clearCustomer('search');
        },

        // "New customer" (or ×): unlinks, and empties only the fields the pick filled that still hold its values; a
        // field typed by hand stays. The address is cleared only when it is still the picked one.
        clearCustomer(focus = 'first_name') {
            const filled = this.filled ?? {};
            const address = this.picked?.address ?? null;
            FIELDS.forEach((f) => {
                const el = this.field(f);
                if (el && f in filled && filled[f] !== '' && untouched(f, el.value, filled[f])) {
                    this.set(f, '');
                }
            });
            if (address && this.addressUntouched(address)) {
                window.dispatchEvent(new CustomEvent('ph-fill', { detail: { prefix: 'recipient', address: {} } }));
            }
            this.unlink();
            this.status = '';
            this.$nextTick?.(() => (focus === 'search' ? byId('f-customer-search') : this.field('first_name'))?.focus());
        },

        addressUntouched(address) {
            const posted = (name) => (typeof document === 'undefined' ? null : document.querySelector(`[name="recipient[${name}]"]`))?.value ?? '';

            const codes = address.codes && typeof address.codes === 'object' ? address.codes : {};
            // A level saved with its code is compared by code (the list's name may be spelled differently).
            const level = (k) => (codes[k] ? same(posted(`codes][${k}`), codes[k]) : same(posted(k), address[k]));

            return ['state', 'city', 'barangay'].every(level) && (!address.postcode || same(posted('postcode'), address.postcode));
        },
    };
}
