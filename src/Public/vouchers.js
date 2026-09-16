/* Vouchers screen (Phase 27) — modal state only. No drag interactions on this
 * screen, so unlike packaging.js / providers.js there is no reorder wiring. */

function vouchersModals() {
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
