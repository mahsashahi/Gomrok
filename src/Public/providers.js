/* Providers screen (Phase 27) — modal state + native HTML5 drag-to-reorder
 * for a routing group's account priority chain. Same shape as packaging.js;
 * kept as its own file since the two screens' modals/selectors differ. */

function providersModals() {
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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-reorder-list]').forEach((container) => {
        let dragEl = null;

        const rows = () => Array.from(container.querySelectorAll('.account-row-drag'));

        rows().forEach((row) => {
            row.addEventListener('dragstart', () => {
                dragEl = row;
                row.classList.add('dragging');
            });
            row.addEventListener('dragend', () => {
                row.classList.remove('dragging');
                rows().forEach((r) => r.classList.remove('drag-over'));
            });
            row.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (row !== dragEl) {
                    row.classList.add('drag-over');
                }
            });
            row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
            row.addEventListener('drop', (e) => {
                e.preventDefault();
                row.classList.remove('drag-over');
                if (!dragEl || row === dragEl) {
                    return;
                }
                const list = rows();
                const dragIdx = list.indexOf(dragEl);
                const dropIdx = list.indexOf(row);
                if (dragIdx < dropIdx) {
                    row.after(dragEl);
                } else {
                    row.before(dragEl);
                }
                submitOrder(container);
            });
        });
    });
});

function submitOrder(container) {
    const ids = Array.from(container.querySelectorAll('.account-row-drag'))
        .map((r) => r.dataset.accountId)
        .join(',');
    const form = container.querySelector('form[data-reorder-form]');
    if (!form) {
        return;
    }
    form.querySelector('input[name=order]').value = ids;
    form.submit();
}
