// Money on the Add shipment form, in whole centavos (BigInt): never a float. The server recomputes all of it, after
// the same normalisation and with the same patterns (ShipmentFormRequest::normaliseValue / QTY_PATTERN / VALUE_PATTERN).
const QTY = /^\d{1,6}$/;
const PRICE = /^\d{1,10}(\.\d{1,2})?$/;

export function normaliseQuantity(quantity) {
    return String(quantity ?? '').trim().replace(/^\+/, '');
}

export function normalisePrice(price) {
    const s = String(price ?? '').trim().replace(/^\+/, '');
    const m = s.match(/^(\d*)\.(\d{0,2})$/);
    return m && m[1] + m[2] !== '' ? `${m[1] === '' ? '0' : m[1]}.${m[2].padEnd(2, '0')}` : s;
}

export function cents(price) {
    const s = normalisePrice(price);
    if (!PRICE.test(s)) {
        return 0n;
    }
    const [whole, frac = ''] = s.split('.');
    return BigInt(whole) * 100n + BigInt(frac.padEnd(2, '0'));
}

export function peso(value) {
    const whole = (value / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `₱${whole}.${(value % 100n).toString().padStart(2, '0')}`;
}

export function shipmentForm(config) {
    return {
        account: config.account,
        service: config.service,
        method: config.method,
        insure: config.insure,
        value: config.value,
        rows: [],
        seq: 0,

        init() {
            this.rows = config.rows.map((row, n) => this.make(row, config.errors[n] ?? {}));
            this.$watch('account', () => {
                if (!this.serviceOptions.some((o) => o.value === this.service)) {
                    this.service = '';
                }
            });
        },

        get serviceOptions() {
            return config.services[this.account] ?? [];
        },

        get courierName() {
            return config.names[this.account] ?? '—';
        },

        get totalCents() {
            return cents(this.value);
        },

        get total() {
            return peso(this.totalCents);
        },

        get count() {
            return this.rows.reduce((sum, row) => {
                const q = normaliseQuantity(row.quantity);
                return sum + (QTY.test(q) ? Number(q) : 0);
            }, 0);
        },

        make(row, errors = {}) {
            return { key: ++this.seq, name: row.name, quantity: row.quantity, errors };
        },

        add() {
            this.rows.push(this.make({ name: '', quantity: '1' }));
        },

        remove(n) {
            if (this.rows.length > 1) {
                this.rows.splice(n, 1);
            }
        },
    };
}
