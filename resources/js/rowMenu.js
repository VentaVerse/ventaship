// A row's actions menu. It is position: fixed, placed from its trigger, so no panel or table edge (overflow: hidden)
// can clip it. Below the trigger with right edges aligned; above when there is no room below; always inside the
// viewport. It closes on Esc (focus back on the trigger), a click outside, Tab, scroll and resize.

const GAP = 4;
const MARGIN = 8;

export function menuPosition(trigger, menu, viewport) {
    const maxLeft = viewport.width - MARGIN - menu.width;
    const left = Math.max(MARGIN, Math.min(trigger.right - menu.width, maxLeft));

    let top = trigger.bottom + GAP;
    const above = trigger.top - GAP - menu.height;
    if (top + menu.height > viewport.height - MARGIN) {
        top = above >= MARGIN ? above : Math.max(MARGIN, viewport.height - MARGIN - menu.height);
    }

    return { top, left };
}

// The item to focus after a key press, or null when the key does not move focus.
export function nextIndex(key, current, count) {
    if (count < 1) {
        return null;
    }
    switch (key) {
        case 'ArrowDown':
            return (current + 1) % count;
        case 'ArrowUp':
            return current <= 0 ? count - 1 : current - 1;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

export function rowMenu() {
    return {
        open: false,
        onMove: null,

        toggle() {
            if (this.open) {
                this.close();
            } else {
                this.show();
            }
        },

        show() {
            this.open = true;
            this.$nextTick(() => {
                this.place();
                this.items()[0]?.focus({ preventScroll: true });
            });
            this.onMove = () => this.close(false);
            window.addEventListener('scroll', this.onMove, { capture: true, passive: true });
            window.addEventListener('resize', this.onMove);
        },

        close(refocus = true) {
            if (!this.open) {
                return;
            }
            this.open = false;
            window.removeEventListener('scroll', this.onMove, { capture: true });
            window.removeEventListener('resize', this.onMove);
            this.onMove = null;
            if (refocus) {
                this.$refs.trigger.focus({ preventScroll: true });
            }
        },

        destroy() {
            this.close(false);
        },

        place() {
            const menu = this.$refs.menu;
            const { top, left } = menuPosition(
                this.$refs.trigger.getBoundingClientRect(),
                { width: menu.offsetWidth, height: menu.offsetHeight },
                { width: document.documentElement.clientWidth, height: window.innerHeight },
            );
            menu.style.top = `${top}px`;
            menu.style.left = `${left}px`;
        },

        items() {
            return [...this.$refs.menu.querySelectorAll('[role="menuitem"]')];
        },

        onKey(event) {
            if (event.key === 'Tab') {
                this.close(false);
                return;
            }
            const items = this.items();
            const next = nextIndex(event.key, items.indexOf(document.activeElement), items.length);
            if (next !== null) {
                event.preventDefault();
                items[next].focus();
            }
        },

        // Runs an item's action with the menu closed and focus back on its trigger first, so a dialog the action
        // opens takes focus from there.
        run(action) {
            this.close();
            action();
        },
    };
}
