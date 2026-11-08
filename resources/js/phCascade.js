export const PH_LEVELS = ['state', 'city', 'district', 'barangay'];
export const PH_LIST = { state: 'provinces', city: 'cities', district: 'districts', barangay: 'barangays' };

// Province > city > (Manila district >) barangay. Children load when a parent changes and everything below it is
// cleared. A level whose saved text matched nothing stays editable text until "Choose from list" is pressed.
// Every level has its own ticket: clearing or reloading a level invalidates anything still in flight for it, and a
// response is applied only if its ticket is current and the parent selection is still the one it started from.
export function phCascade(config) {
    return {
        sel: config.sel,
        free: config.free,
        text: config.text,
        lists: config.lists,
        postcode: config.postcode,
        postcodeEdited: false,
        error: null,
        tickets: { state: 0, city: 0, district: 0, barangay: 0 },

        async fetchList(level, parent) {
            const res = await fetch(`${config.url}/${PH_LIST[level]}?parent=${encodeURIComponent(parent)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                throw new Error('load failed');
            }
            const data = await res.json();

            return Array.isArray(data) ? data : [];
        },

        options(level) {
            return this.lists[PH_LIST[level]] ?? [];
        },

        row(level) {
            return this.options(level).find((o) => o.code === this.sel[level]) ?? null;
        },

        hasDistricts() {
            return !this.free.city && this.sel.city !== '' && this.lists.districts.length > 0;
        },

        show(level) {
            return level !== 'district' || this.hasDistricts() || this.free.district;
        },

        isFree(level) {
            return this.free[level];
        },

        enabled(level) {
            if (level === 'state') {
                return true;
            }
            if (level === 'city') {
                return this.sel.state !== '';
            }
            if (level === 'district') {
                return this.sel.city !== '';
            }

            return this.sel.city !== '' && (!this.hasDistricts() || this.sel.district !== '');
        },

        canChoose(level) {
            const above = PH_LEVELS.slice(0, PH_LEVELS.indexOf(level));

            return this.free[level] && !above.some((l) => this.free[l]);
        },

        name(level) {
            if (level === 'district' && !this.show('district')) {
                return '';
            }
            if (this.free[level]) {
                return this.text[level].trim();
            }

            return this.row(level)?.name ?? '';
        },

        code(level) {
            return this.free[level] ? '' : this.sel[level];
        },

        below(level) {
            return PH_LEVELS.slice(PH_LEVELS.indexOf(level) + 1);
        },

        clear(levels) {
            levels.forEach((l) => {
                this.tickets[l]++;
                this.sel[l] = '';
                this.free[l] = false;
                this.text[l] = '';
                this.lists[PH_LIST[l]] = l === 'state' ? this.lists.provinces : [];
            });
            this.error = null;
        },

        // Loads the children of `from` into `level`. False when it failed or went stale; a failure on the live
        // request is recorded in `error` so the template can offer Retry.
        async load(level, parent, from) {
            const ticket = ++this.tickets[level];
            const value = this.sel[from];
            const current = () => ticket === this.tickets[level] && this.sel[from] === value;
            this.error = null;
            try {
                const data = await this.fetchList(level, parent);
                if (!current()) {
                    return false;
                }
                this.lists[PH_LIST[level]] = data;

                return true;
            } catch (e) {
                if (current()) {
                    this.lists[PH_LIST[level]] = [];
                    this.error = { from };
                }

                return false;
            }
        },

        // Loads whatever hangs under the chosen value of `from`.
        async reload(from) {
            const value = this.sel[from];
            if (value === '') {
                return;
            }
            if (from === 'state') {
                await this.load('city', value, 'state');
            } else if (from === 'city') {
                if ((await this.load('district', value, 'city')) && this.lists.districts.length === 0) {
                    await this.load('barangay', value, 'city');
                }
            } else if (from === 'district') {
                await this.load('barangay', value, 'district');
            }
        },

        applyZip() {
            if (!this.postcodeEdited) {
                this.postcode = this.row('city')?.zip ?? '';
            }
        },

        async changed(level) {
            this.clear(this.below(level));
            if (this.sel[level] === '') {
                return;
            }
            if (level === 'city') {
                this.applyZip();
            }
            await this.reload(level);
        },

        async retry() {
            if (this.error) {
                await this.reload(this.error.from);
            }
        },

        async choose(level) {
            this.clear([level, ...this.below(level)]);
            if (typeof this.$nextTick === 'function') {
                this.$nextTick(() => this.$root.querySelector(`select[id$="-${level}"]`)?.focus());
            }
            if (level === 'city') {
                await this.reload('state');
            } else if (level === 'district') {
                await this.reload('city');
            } else if (level === 'barangay') {
                await this.reload(this.sel.district ? 'district' : 'city');
            }
        },
    };
}
