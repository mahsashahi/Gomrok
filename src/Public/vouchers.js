/* Vouchers screen (Phase 27) — modal state only. No drag interactions on this
 * screen, so unlike packaging.js / providers.js there is no reorder wiring.
 * Modal state itself is the shared adminModalState() (admin-modal-state.js) —
 * see .claude/docs/Ui.md's validation-preserving forms rule. */

function vouchersModals() {
    return adminModalState();
}
