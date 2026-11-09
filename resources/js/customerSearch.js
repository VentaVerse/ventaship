// The Add / Edit shipment form's customer search: a combobox over GET /customers/search. The rows are the matches
// plus a last "New customer" row. Picking a customer fills the recipient's name, phone and street, and sends the
// address to the recipient PSGC cascade (phCascade.fill) through the `ph-fill` window event.
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

const digits = (v) => String(v ?? '').replace(/\D+/g, '');

const byId = (id) => (typeof document === 'undefined' ? null : document.getElementById(id));

export function customerSearch(config) {
    return {
        q: '',
        customerId: config.customerId ?? '',
        pickedPhone: '',
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
            this.customerId = customer ? String(customer.public_id ?? '') : '';
            if (customer) {
                this.pickedPhone = digits(recipientValues(customer.address ?? {}).phone);
                this.fill(customer);
                this.status = `${customer.name} filled`;
            } else {
                this.clearCustomer();
            }
        },

        // Typing another phone after picking a customer makes it a new customer again (and brings back the switch).
        edited(e) {
            if (e?.isTrusted && e.target?.name === 'recipient[phone]' && this.customerId !== '' && digits(e.target.value) !== this.pickedPhone) {
                this.customerId = '';
            }
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

        clearCustomer() {
            FIELDS.forEach((f) => this.set(f, ''));
            window.dispatchEvent(new CustomEvent('ph-fill', { detail: { prefix: 'recipient', address: {} } }));
            this.status = '';
            this.$nextTick?.(() => this.field('first_name')?.focus());
        },
    };
}
