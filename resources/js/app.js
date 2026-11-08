import './bootstrap';
import Alpine from 'alpinejs';

Alpine.data('confirmDialog', () => ({
    open: false,
    title: '',
    confirm: 'Confirm',
    form: null,

    show(detail) {
        this.title = detail.title ?? '';
        this.confirm = detail.confirm ?? 'Confirm';
        this.form = detail.form ?? null;
        this.open = true;
        this.$nextTick(() => this.$refs.dialog.focus());
    },

    close() {
        this.open = false;
        this.form = null;
    },

    submit() {
        const form = this.form ? document.getElementById(this.form) : null;
        this.close();
        if (form) {
            form.requestSubmit();
        }
    },
}));

window.Alpine = Alpine;
Alpine.start();
