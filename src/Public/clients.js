/* Clients screen (Phase 27) — modal state only. No drag interactions. */

function clientsModals() {
    return {
        modal: null,
        form: {},
        open(name, data) {
            this.modal = name;
            this.form = Object.assign({}, data || {});
        },
        close() {
            this.modal = null;
            this.form = {};
        },
    };
}

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        if (!btn) return;
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = original; }, 1500);
    });
}
