/* Shared Alpine modal-state factory used by every admin screen's create/edit
 * modals (Clients, Packaging & Pricing, Providers, Vouchers, Admin Users).
 *
 * Validation-preserving forms rule (.claude/docs/Ui.md): a validation failure
 * must never close the modal, reset the form, or lose what the user typed.
 * Every write action still does a real `<form method=post>` submit (no
 * fetch/AJAX — Ui.md's existing "never intercepts the submit" note stays
 * true), but on failure the action re-renders the same screen instead of
 * redirecting, passing back the submitted values as `reopen_modal` in the
 * Twig context. Each screen's root `x-data` element then carries a
 * conditional `x-init="open(name, values, error, fieldErrors)"` that fires
 * once on load, reopening the modal exactly as the user left it.
 *
 * Every per-screen `*Modals()` factory (packagingModals(), clientsModals(),
 * providersModals(), vouchersModals(), adminUsersModals()) returns this
 * shape, so the reopen wiring is identical everywhere. */
function adminModalState() {
    return {
        modal: null,
        form: {},
        error: null,
        fieldErrors: {},
        open(name, data, error, fieldErrors) {
            this.modal = name;
            this.form = Object.assign({}, data || {});
            this.error = error || null;
            this.fieldErrors = fieldErrors || {};
        },
        close() {
            this.modal = null;
            this.form = {};
            this.error = null;
            this.fieldErrors = {};
        },
        dismissError() {
            this.error = null;
        },
    };
}
