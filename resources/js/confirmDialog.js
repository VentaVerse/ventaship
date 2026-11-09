// The app-wide confirm dialog (<x-ui.confirm>), opened by a `confirm` window event:
//   { title, confirm, dismiss, form, submitter, tone }
// `tone: 'danger'` makes the confirm button red. Focus starts on the safe button, Tab and Shift+Tab stay inside the
// dialog, Esc and a click outside close it, and focus goes back to whatever opened it.

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function focusables(container) {
    return [...container.querySelectorAll(FOCUSABLE)].filter((el) => !el.hidden && el.getAttribute('aria-hidden') !== 'true');
}

// The element Tab (or Shift+Tab) should move to so focus wraps inside the dialog, or null to let the browser move it.
export function wrapTarget(items, active, backwards) {
    if (items.length === 0) {
        return null;
    }
    const first = items[0];
    const last = items[items.length - 1];
    const index = items.indexOf(active);
    if (index === -1) {
        return backwards ? last : first;
    }
    if (backwards && active === first) {
        return last;
    }
    if (!backwards && active === last) {
        return first;
    }
    return null;
}

export function confirmTone(detail) {
    return detail?.tone === 'danger' ? 'danger' : 'primary';
}

export function confirmDialog() {
    return {
        open: false,
        title: '',
        confirm: 'Confirm',
        dismiss: 'Back',
        tone: 'primary',
        form: null,
        submitter: null,
        opener: null,

        show(detail) {
            this.title = detail?.title ?? '';
            this.confirm = detail?.confirm ?? 'Confirm';
            this.dismiss = detail?.dismiss ?? 'Back';
            this.tone = confirmTone(detail);
            this.form = detail?.form ?? null;
            this.submitter = detail?.submitter ?? null;
            const active = document.activeElement;
            this.opener = active && active !== document.body ? active : null;
            this.open = true;
            this.$nextTick(() => this.$refs.safe.focus());
        },

        close(restore = true) {
            if (!this.open) {
                return;
            }
            this.open = false;
            this.form = null;
            this.submitter = null;
            const opener = this.opener;
            this.opener = null;
            if (restore && opener?.isConnected) {
                this.$nextTick(() => opener.focus({ preventScroll: true }));
            }
        },

        trap(event) {
            const target = wrapTarget(focusables(this.$refs.dialog), document.activeElement, event.shiftKey);
            if (target) {
                event.preventDefault();
                target.focus();
            }
        },

        submit() {
            const form = this.form ? document.getElementById(this.form) : null;
            const submitter = this.submitter ? document.getElementById(this.submitter) : null;
            this.close();
            if (form) {
                form.requestSubmit(submitter ?? undefined);
            }
        },
    };
}
