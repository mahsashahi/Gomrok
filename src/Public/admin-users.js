/* Admin Users screen (Phase 27) — modal state only. */

function adminUsersModals() {
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
