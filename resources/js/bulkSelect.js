const FLAGS = ['printable', 'bookable', 'cancellable'];

/**
 * The Shipments list selection. Each row carries server-computed flags (data-printable, data-bookable,
 * data-cancellable); every bulk action counts and sends only the ticked rows it applies to.
 * Print waybills fetches the labels as JSON and opens the PDF in a tab opened at the click, so it is not blocked.
 */
export function bulkSelect(config) {
    return {
        count: 0,
        n: { printable: 0, bookable: 0, cancellable: 0 },
        busy: false,
        printError: null,

        boxes() {
            return [...this.$root.querySelectorAll('input[name="ids[]"]:checked')];
        },

        ids(flag) {
            return this.boxes()
                .filter((box) => box.closest('[data-id]')?.dataset[flag] === '1')
                .map((box) => box.value);
        },

        sync() {
            const boxes = this.boxes();
            const n = { printable: 0, bookable: 0, cancellable: 0 };
            for (const box of boxes) {
                const row = box.closest('[data-id]');
                for (const flag of FLAGS) {
                    if (row?.dataset[flag] === '1') {
                        n[flag]++;
                    }
                }
            }
            this.count = boxes.length;
            this.n = n;
            if (boxes.length === 0) {
                this.printError = null;
            }
        },

        label(text, n) {
            return n > 0 ? `${text} (${n})` : text;
        },

        clear() {
            for (const box of this.boxes()) {
                box.checked = false;
            }
            this.printError = null;
            this.sync();
        },

        /** The hidden #bulk-run form, emptied and given these ids and this action. */
        fill(action, ids) {
            const form = document.getElementById('bulk-run');
            if (!form || !action) {
                return null;
            }
            form.action = action;
            form.querySelectorAll('input[name="ids[]"]').forEach((input) => input.remove());
            for (const id of ids) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            }

            return form;
        },

        run(flag, action) {
            const ids = this.ids(flag);
            if (ids.length > 0) {
                this.fill(config[action], ids)?.requestSubmit();
            }
        },

        confirmCancel() {
            const ids = this.ids('cancellable');
            if (ids.length === 0 || !this.fill(config.cancel, ids)) {
                return;
            }
            const many = ids.length === 1 ? 'shipment' : 'shipments';
            this.$dispatch('confirm', {
                title: `Cancel ${ids.length} ${many}?`,
                confirm: 'Cancel shipments',
                dismiss: 'Keep shipments',
                form: 'bulk-run',
            });
        },

        async print() {
            const ids = this.ids('printable');
            if (ids.length === 0 || this.busy || !config.labels) {
                return;
            }
            this.busy = true;
            this.printError = null;
            // Opened now, inside the click, so no popup blocker stops it; pointed at the PDF once it is ready.
            const tab = window.open('', '_blank');
            try {
                const res = await fetch(config.labels, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ ids }),
                });
                const body = await res.json().catch(() => null);
                if (!res.ok || !body || !Array.isArray(body.ok)) {
                    throw new Error('print failed');
                }
                const url = typeof body.url === 'string' && body.url.startsWith('/') && !body.url.startsWith('//') ? body.url : null;
                if (body.ok.length > 0 && url) {
                    if (tab) {
                        tab.opener = null;
                        tab.location.href = url;
                    } else {
                        window.location.assign(url);
                    }
                } else {
                    tab?.close();
                }
                const failed = Array.isArray(body.failed) ? body.failed : [];
                if (failed.length > 0) {
                    this.printError = {
                        title: `${failed.length} ${failed.length === 1 ? 'waybill' : 'waybills'} couldn't be printed.`,
                        items: failed.map((f) => ({
                            id: String(f.id),
                            line: `${f.name ?? f.id}${f.tracking ? ` · ${f.tracking}` : ''}: ${f.reason}`,
                        })),
                    };
                }
            } catch (e) {
                tab?.close();
                this.printError = { title: "Waybills couldn't be printed.", items: [] };
            } finally {
                this.busy = false;
            }
        },
    };
}
