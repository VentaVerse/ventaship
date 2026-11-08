// Money on the Add shipment form, in whole centavos (BigInt): never a float. The server recomputes all of it.
const QTY = /^\d{1,6}$/;
const PRICE = /^\d{1,10}(\.\d{1,2})?$/;

export function cents(price) {
    const s = String(price ?? '').trim();
    if (!PRICE.test(s)) {
        return 0n;
    }
    const [whole, frac = ''] = s.split('.');
    return BigInt(whole) * 100n + BigInt(frac.padEnd(2, '0'));
}

export function lineCents(row) {
    const q = String(row.quantity ?? '').trim();
    return QTY.test(q) ? BigInt(q) * cents(row.price) : 0n;
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
            return this.rows.reduce((sum, row) => sum + lineCents(row), 0n);
        },

        get total() {
            return peso(this.totalCents);
        },

        get count() {
            return this.rows.reduce((sum, row) => sum + (QTY.test(String(row.quantity ?? '').trim()) ? Number(row.quantity) : 0), 0);
        },

        line(row) {
            return peso(lineCents(row));
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
    };
}
