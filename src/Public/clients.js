/* Clients screen (Phase 27) — modal state only. No drag interactions.
 * Modal state itself is the shared adminModalState() (admin-modal-state.js) —
 * see .claude/docs/Ui.md's validation-preserving forms rule. */

function clientsModals() {
    return adminModalState();
}

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        if (!btn) return;
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = original; }, 1500);
    });
}
