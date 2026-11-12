// The Add / Edit shipment form's final check: "Book shipment" opens "Book this shipment?" with a short summary and
// the courier's fee, or its refusal. The server validates and quotes (ShipmentsController::check); nothing is saved
// until "Book shipment" in the dialog submits the form, and the server checks again then.

import { focusables, wrapTarget } from './confirmDialog.js';

// The form summary's placeholder for an empty value (shipmentForm.js), never shown in the dialog.
const EMPTY = '\u2014';

/** A typed number as people write it: "1.50" is "1.5", "35.000" is "35". Anything else is kept as typed. */
export function plainNumber(value) {
    const s = String(value ?? '').trim();
    if (!/^\d+(\.\d+)?$/.test(s)) {
        return s;
    }
    const trimmed = s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
    return trimmed.replace(/^0+(?=\d)/, '');
}

/** "Small · 1 kg · 25 × 20 × 10 cm": the packaging, then the weight and size when the courier asks for them. */
export function parcelLine({ packaging, weight, length, width, height, showWeight, showSize }) {
    const parts = [];
    if (packaging) {
        parts.push(packaging);
    }
    if (showWeight && String(weight ?? '').trim() !== '') {
        parts.push(`${plainNumber(weight)} kg`);
    }
    const sides = [length, width, height].map((v) => String(v ?? '').trim());
    if (showSize && sides.every((v) => v !== '')) {
        parts.push(`${sides.map(plainNumber).join(' × ')} cm`);
    }
    return parts.join(' · ');
}

/**
 * The dialog's summary rows, as [label, value]: courier and handover, the SPX pickup time, the recipient, the
 * parcel, COD when set, and who pays shipping when the courier asks. Empty values are left out.
 */
export function bookingSummary(s) {
    const rows = [];
    const courier = s.courier === 'spx' && s.handoverLabel ? `${s.courierName} · ${s.handoverLabel}` : s.courierName;
    rows.push(['Courier', courier]);
    if (s.courier === 'spx' && s.handover === 'pickup' && s.slotLabel) {
        rows.push(['Pickup', s.slotLabel]);
    }
    rows.push(['To', [s.toName, s.toPlace].map((p) => String(p ?? '').trim()).filter((p) => p !== '').join(' · ')]);
    rows.push(['Parcel', parcelLine(s)]);
    if (s.cod) {
        rows.push(['COD', s.total]);
    }
    if (s.payerLabel) {
        rows.push(['Shipping fee', s.payerLabel]);
    }
    return rows.filter(([, value]) => String(value ?? '').trim() !== '');
}

/**
 * What the dialog shows for the check's answer:
 *   invalid  (422) the form has errors: it is submitted so the page shows them;
 *   refused  the courier will not take it (title and detail are its reason in plain words);
 *   ready    the courier's fee;
 *   none     no fee to show (the courier gave none, or the check failed); booking stays possible.
 */
export function checkResult(status, body) {
    const b = body && typeof body === 'object' ? body : {};
    if (status === 422) {
        return { state: 'invalid' };
    }
    if (typeof b.error === 'string' && b.error !== '') {
        return {
            state: 'refused',
            title: typeof b.title === 'string' && b.title !== '' ? b.title : b.error,
            detail: typeof b.title === 'string' && b.title !== '' && typeof b.detail === 'string' ? b.detail : '',
        };
    }
    if (status >= 200 && status < 300 && typeof b.fee === 'string' && typeof b.label === 'string' && b.label !== '') {
        return { state: 'ready', label: b.label };
    }
    return { state: 'none' };
}

/** Book shipment in the dialog: only once the check has answered and the courier did not refuse. */
export function canBook(state, busy) {
    return !busy && (state === 'ready' || state === 'none');
}

/** The form's own fields for the check: everything a submit would send, without the edit form's PUT override. */
export function checkBody(form, FormDataImpl = FormData) {
    const data = new FormDataImpl(form);
    data.delete('_method');
    return data;
}

/** Mixed into shipmentForm(): `config.checkUrl` is the check endpoint, `config.payers` the who-pays labels. */
export function bookCheck(config) {
    return {
        check: { open: false, state: 'idle', label: '', title: '', detail: '', busy: false },
        checkRows: [],
        checkSeq: 0,
        checkOpener: null,
        checkPassed: false,
        // True while the check runs: the dialog stays shut until the answer is in, so a form with errors never flashes it.
        checking: false,

        // The form's submit: Book shipment (a button marked data-book) opens the check instead, once.
        submitted(event) {
            const submitter = event.submitter;
            if (!submitter || submitter.dataset.book === undefined) {
                return;
            }
            if (this.checkPassed) {
                this.checkPassed = false;
                return;
            }
            event.preventDefault();
            if (!this.checking) {
                this.openCheck(submitter);
            }
        },

        openCheck(submitter) {
            this.checkOpener = submitter;
            // A stored draft's rows come from the server (config.rows); the form builds them from its fields.
            this.checkRows = Array.isArray(config.rows) ? config.rows : bookingSummary(this.summaryState());
            this.check = { open: false, state: 'loading', label: '', title: '', detail: '', busy: false };
            this.checking = true;
            submitter?.setAttribute?.('aria-busy', 'true');
            this.runCheck();
        },

        summaryState() {
            const slot = (this.slots ?? []).find((o) => o.value === this.slot);
            return {
                courier: this.courier,
                courierName: this.courierName,
                handover: this.handover,
                handoverLabel: (config.handovers ?? {})[this.handover] ?? '',
                slotLabel: slot ? slot.label : '',
                toName: this.toName === EMPTY ? '' : this.toName,
                toPlace: this.toPlace,
                packaging: this.packagingText === EMPTY ? '' : this.packagingText,
                weight: this.weight,
                length: this.length,
                width: this.width,
                height: this.height,
                showWeight: this.show.weight,
                showSize: this.show.size,
                cod: this.method === 'cod',
                total: this.total,
                payerLabel: this.show.shippingPayment ? (((config.payers ?? {})[this.courier] ?? {})[this.shippingPayment] ?? '') : '',
            };
        },

        async runCheck() {
            const seq = ++this.checkSeq;
            let status = 0;
            let body = {};
            try {
                const res = await fetch(config.checkUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body: checkBody(this.$root),
                });
                status = res.status;
                body = await res.json().catch(() => ({}));
            } catch (e) {
                status = 0;
            }
            if (seq !== this.checkSeq) {
                return;
            }
            this.checking = false;
            this.checkOpener?.removeAttribute?.('aria-busy');
            const result = checkResult(status, body);
            if (result.state === 'invalid') {
                // The server shows the field errors on the page, exactly as a booking would; the dialog never opens.
                this.submitBooking();
                return;
            }
            Object.assign(this.check, { label: '', title: '', detail: '' }, result, { open: true });
            this.$nextTick(() => this.$refs.checkBack?.focus());
        },

        get canBook() {
            return canBook(this.check.state, this.check.busy);
        },

        confirmBook() {
            if (!this.canBook) {
                return;
            }
            this.check.busy = true;
            this.submitBooking();
        },

        submitBooking() {
            const submitter = this.checkOpener;
            if (!submitter?.isConnected) {
                return;
            }
            this.checkPassed = true;
            this.$root.requestSubmit(submitter);
        },

        closeCheck() {
            if (!this.check.open || this.check.busy) {
                return;
            }
            this.checkSeq++;
            this.check.open = false;
            const opener = this.checkOpener;
            if (opener?.isConnected) {
                this.$nextTick(() => opener.focus({ preventScroll: true }));
            }
        },

        trapCheck(event) {
            const target = wrapTarget(focusables(this.$refs.checkDialog), document.activeElement, event.shiftKey);
            if (target) {
                event.preventDefault();
                target.focus();
            }
        },
    };
}
