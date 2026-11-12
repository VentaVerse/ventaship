import { bookCheck } from './bookCheck.js';

// Money on the Add shipment form, in whole centavos (BigInt): never a float. The server recomputes all of it, after
// the same normalisation and with the same pattern (ShipmentFormRequest::normaliseValue / VALUE_PATTERN).
const PRICE = /^\d{1,10}(\.\d{1,2})?$/;

/** The one Item field as the summary shows it. */
export function itemText(item) {
    const s = String(item ?? '').trim();
    return s === '' ? '—' : s;
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

// Courier-specific fields; the server keeps the same rules in App\Shipments\CourierFormFields.
const OWN_PACKAGING = { spx: 'custom', lbc: '16' };
const LBC_KILOBOX = ['165', '6', '164', '63', '7', '64'];
const SHIPPING_PAYMENT_DEFAULT = { quadx: 'cash', spx: 'sender_pay' };
const DEFAULT_CATEGORY = 'General merchandise';

/** Which courier fields show for a courier and its chosen packaging. A hidden field is disabled, so it never posts. */
export function courierFields(courier, parcel, sizeOpen = false) {
    const own = OWN_PACKAGING[courier] !== undefined && OWN_PACKAGING[courier] === parcel;
    const addSize = courier === 'lbc' && LBC_KILOBOX.includes(parcel);

    // SPX always takes a size; a preset fills it in.
    const sized = own || courier === 'spx';

    return {
        service: courier === 'quadx',
        weight: courier === 'spx' || courier === 'lbc',
        size: sized || (addSize && sizeOpen),
        sizeRequired: sized,
        addSize,
        category: courier === 'spx',
        shippingPayment: courier === 'quadx' || courier === 'spx',
        handover: courier === 'spx',
        pickupDate: courier === 'lbc',
        note: courier === 'quadx' || courier === 'spx',
    };
}

/** The courier choices after switching to another courier: that courier's defaults, nothing carried over. */
export function courierReset(courier, { parcel, firstDate }) {
    return {
        parcel: parcel ?? '',
        length: '',
        width: '',
        height: '',
        sizeOpen: false,
        shippingPayment: SHIPPING_PAYMENT_DEFAULT[courier] ?? '',
        category: DEFAULT_CATEGORY,
        handover: 'dropoff',
        slot: '',
        slots: [],
        slotState: 'idle',
        pickupDate: firstDate ?? '',
    };
}

/** The size a preset fills in, as form values, or null when the packaging has none (own packaging, other couriers). */
export function presetSize(sizes, parcel) {
    const size = (sizes ?? {})[parcel];
    if (!size) {
        return null;
    }
    return { length: String(size.length_cm), width: String(size.width_cm), height: String(size.height_cm) };
}

const PICKUP_KEYS = ['contact_name', 'contact_phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'];

/** The query entries that describe a pickup address to the SPX pickup-time lookup; blanks left out. */
export function pickupParams(pickup) {
    if (!pickup || typeof pickup !== 'object') {
        return [];
    }
    const out = [];
    for (const key of PICKUP_KEYS) {
        if (typeof pickup[key] === 'string' && pickup[key] !== '') {
            out.push([`pickup[${key}]`, pickup[key]]);
        }
    }
    const codes = pickup.codes && typeof pickup.codes === 'object' ? pickup.codes : {};
    for (const level of ['state', 'city', 'barangay']) {
        if (typeof codes[level] === 'string' && codes[level] !== '') {
            out.push([`pickup[codes][${level}]`, codes[level]]);
        }
    }

    return out;
}

/** The summary's "To": the recipient's name, and "City, Province" under it. */
export function recipientName(first, last) {
    return [first, last].map((p) => String(p ?? '').trim()).filter((p) => p !== '').join(' ');
}

export function placeLine(city, state) {
    return [city, state].map((p) => String(p ?? '').trim()).filter((p) => p !== '').join(', ');
}

/** Whether the SPX pickup-time list should be fetched now: SPX, pickup chosen, and nothing loaded or loading yet. */
export function shouldAutoLoadSlots(courier, handover, slotState) {
    return courier === 'spx' && handover === 'pickup' && (slotState ?? 'idle') === 'idle';
}

export function shipmentForm(config) {
    const form = {
        account: config.account,
        service: config.service,
        method: config.method,
        insure: config.insure,
        value: config.value,
        item: config.item ?? '',
        pickupId: config.pickupId ?? '',
        pickupOpen: false,
        firstName: config.to?.first ?? '',
        lastName: config.to?.last ?? '',
        toPlace: config.to?.place ?? '',
        ...config.courier,
        slotSeq: 0,

        init() {
            this.$watch('account', () => {
                if (!this.serviceOptions.some((o) => o.value === this.service)) {
                    this.service = '';
                }
                this.slotSeq++;
                Object.assign(this, courierReset(this.courier, {
                    parcel: config.defaultParcels[this.account] ?? null,
                    firstDate: config.firstDate,
                }));
                this.fillPresetSize();
            });
            // Picking an SPX preset fills its size in; own packaging starts empty.
            this.$watch('parcel', () => this.fillPresetSize());
            // SPX pickup times belong to the pickup address they were loaded for.
            this.$watch('pickupId', () => {
                this.slotSeq++;
                Object.assign(this, { slot: '', slots: [], slotState: 'idle' });
                this.$nextTick(() => this.autoLoadSlots());
            });
            // Pickup times load by themselves once SPX pickup is chosen: there is no "Load times" step.
            this.$watch('account', () => this.$nextTick(() => this.autoLoadSlots()));
            this.$watch('handover', () => this.autoLoadSlots());
            this.autoLoadSlots();
        },

        fillPresetSize() {
            if (this.courier !== 'spx') {
                return;
            }
            const size = presetSize((config.parcelSizes ?? {})[this.account], this.parcel);
            Object.assign(this, size ?? { length: '', width: '', height: '' });
        },

        autoLoadSlots() {
            if (shouldAutoLoadSlots(this.courier, this.handover, this.slotState)) {
                this.loadSlots();
            }
        },

        get courier() {
            return config.couriers[this.account] ?? '';
        },

        get show() {
            return courierFields(this.courier, this.parcel, this.sizeOpen);
        },

        async loadSlots() {
            const seq = ++this.slotSeq;
            this.slotState = 'loading';
            const params = new URLSearchParams([['account_id', this.account], ...pickupParams((config.pickups ?? {})[this.pickupId])]);
            try {
                const res = await fetch(`${config.slotsUrl}?${params}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const body = await res.json().catch(() => ({}));
                if (seq !== this.slotSeq) {
                    return;
                }
                if (!res.ok || !Array.isArray(body.slots) || body.slots.length === 0) {
                    this.slotState = 'error';
                    return;
                }
                this.slots = body.slots;
                if (!this.slots.some((o) => o.value === this.slot)) {
                    this.slot = this.slots[0].value;
                }
                this.slotState = 'ready';
            } catch (e) {
                if (seq === this.slotSeq) {
                    this.slotState = 'error';
                }
            }
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

        get itemText() {
            return itemText(this.item);
        },

        get toName() {
            return recipientName(this.firstName, this.lastName) || '—';
        },

        get packagingText() {
            return ((config.parcelLabels ?? {})[this.account] ?? {})[this.parcel] ?? '—';
        },

        // Input events from the recipient fields (typed, or filled by the customer search) keep the summary current.
        recipientTyped(e) {
            const name = e?.target?.name;
            if (name === 'recipient[first_name]') {
                this.firstName = e.target.value;
            } else if (name === 'recipient[last_name]') {
                this.lastName = e.target.value;
            }
        },

        // The recipient cascade's `ph-place` event.
        placed(e) {
            if (e?.detail?.prefix === 'recipient') {
                this.toPlace = placeLine(e.detail.city, e.detail.state);
            }
        },
    };

    // The final check before booking, merged with its getters intact (a spread would copy their values once).
    return Object.defineProperties(form, Object.getOwnPropertyDescriptors(bookCheck(config)));
}
