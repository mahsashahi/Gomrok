/* Packaging & Pricing screen (Phase 27 Increment B) — modal state + native
 * HTML5 drag-to-reorder. Every modal form is a plain <form method=post>; this
 * only opens/closes the modal and prefills it, it never intercepts the
 * submit — the write always goes through a real POST to an admin action. */

function packagingModals() {
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

        const rows = () => Array.from(container.querySelectorAll('.package-row-drag'));

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
    const ids = Array.from(container.querySelectorAll('.package-row-drag'))
        .map((r) => r.dataset.packageId)
        .join(',');
    const form = container.querySelector('form[data-reorder-form]');
    if (!form) {
        return;
    }
    form.querySelector('input[name=order]').value = ids;
    form.submit();
}
