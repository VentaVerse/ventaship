// The courier account page (/accounts/{courier}). The form (accountForm) tracks the selected environment and whether
// anything differs from what was loaded, and announces both on a window `account-form` event; the page header
// (accountHeader: the environment and check badges, Unsaved, Test connection) follows it.

const ENV = {
    production: { label: 'Production', tone: 'attn' },
    sandbox: { label: 'Sandbox', tone: 'cool' },
};

const TONES = ['good', 'bad', 'mute', 'attn', 'cool'];

// Posted fields that are not the account's values: the CSRF token, the method override, the Save and test flag. An
// empty secret is not a change either (an unset key's empty input, or an Edit not yet typed into).
function counts([name, value]) {
    if (name === '_token' || name === '_method' || name === 'after') {
        return false;
    }
    return !(name.startsWith('secrets[') && value === '');
}

/** The form's values as one comparable string. @param {Iterable<[string, FormDataEntryValue]>} entries */
export function formSnapshot(entries) {
    return JSON.stringify([...entries].filter(counts).map(([name, value]) => [name, typeof value === 'string' ? value : '']));
}

/** Unsaved: something was edited, or the selected environment is not the saved one (null: no account yet). */
export function isUnsaved({ env, saved, dirty }) {
    return Boolean(dirty) || (saved !== null && saved !== undefined && env !== saved);
}

/**
 * The header's badges. The check badge shows the saved environment's last check only while that environment is
 * selected; otherwise it was never checked. `check` is [label, tone]; with no account yet (saved null) it is shown as
 * it is (Not set up).
 */
export function headerView({ env, saved, check }) {
    const e = ENV[env] ?? ENV.sandbox;
    const current = (saved === null || saved === undefined || env === saved) && Array.isArray(check);
    const [checkLabel, checkTone] = current ? check : ['Not checked', 'mute'];

    return { envLabel: e.label, envTone: e.tone, checkLabel, checkTone };
}

/** Class bindings for a badge of the given tone (only that tone's modifier on). */
export function toneClasses(tone) {
    return Object.fromEntries(TONES.map((t) => [`x-badge--${t}`, t === tone]));
}

/**
 * Which hidden confirmed submitter to ask the confirm dialog for, or null to let the submit go ahead. Switching to
 * Production (from Sandbox, or on the first save) is confirmed once; the confirmed buttons carry the same intent as the
 * button pressed (Save, or Save and test).
 */
export function confirmSubmitter({ env, saved, submitter }) {
    if (env !== 'production' || saved === 'production') {
        return null;
    }
    const id = submitter?.id ?? '';
    if (id === 'account-save-confirmed' || id === 'account-save-test-confirmed') {
        return null;
    }
    return submitter?.value === 'test' ? 'account-save-test-confirmed' : 'account-save-confirmed';
}

export function accountForm(config) {
    return {
        env: config.env,
        saved: config.saved ?? null,
        status: '',
        initial: '',
        dirty: Boolean(config.dirty),

        init() {
            this.initial = this.snapshot();
            this.$watch('env', () => this.refresh());
            this.publish();
        },

        snapshot() {
            return formSnapshot(new FormData(this.$root));
        },

        get unsaved() {
            return isUnsaved({ env: this.env, saved: this.saved, dirty: this.dirty });
        },

        // A value that comes back from a refused save is unsaved from the start (config.dirty), and stays so.
        refresh() {
            this.dirty = Boolean(config.dirty) || this.snapshot() !== this.initial;
            this.publish();
        },

        publish() {
            window.dispatchEvent(new CustomEvent('account-form', { detail: { env: this.env, unsaved: this.unsaved } }));
        },

        submit(event) {
            const confirmed = confirmSubmitter({ env: this.env, saved: this.saved, submitter: event.submitter });
            if (confirmed === null) {
                return;
            }
            event.preventDefault();
            this.$dispatch('confirm', { ...config.confirm, submitter: confirmed });
        },
    };
}

export function accountHeader(config) {
    return {
        env: config.env,
        unsaved: Boolean(config.unsaved),

        get view() {
            return headerView({ env: this.env, saved: config.saved ?? null, check: config.check });
        },

        toneClasses(tone) {
            return toneClasses(tone);
        },

        sync(detail) {
            this.env = detail?.env ?? this.env;
            this.unsaved = Boolean(detail?.unsaved);
        },
    };
}
