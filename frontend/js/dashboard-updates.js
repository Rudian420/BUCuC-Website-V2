// Dynamic row manager for dashboard_updates.php
(function () {
    const container = document.getElementById('growthRowsContainer');
    const addBtn = document.getElementById('addGrowthRowBtn') || document.querySelector('button[onclick*="addGrowthRow"]');

    function removeRow(btn) {
        if (!container) return;
        if (container.children.length > 1) {
            const row = btn.closest('.dynamic-row');
            if (row) row.remove();
        } else {
            if (window.showNotification) {
                showNotification('The chart needs at least one data point, so the last row stays. Change its values instead.', 'warning');
            }
        }
    }

    function addGrowthRow() {
        if (!container) return;
        const newRow = document.createElement('div');
        newRow.className = 'row dynamic-row align-items-end';
        newRow.innerHTML = `
            <div class="col-md-5">
                <label class="form-label small">Label</label>
                <input type="text" class="form-control" name="mg_label[]" placeholder="e.g. Next Year" required>
            </div>
            <div class="col-md-5">
                <label class="form-label small">Value</label>
                <input type="number" class="form-control" name="mg_value[]" value="0" required min="0">
            </div>
            <div class="col-md-2 text-center">
                <i class="fas fa-trash remove-row-btn" role="button" aria-label="Remove row"></i>
            </div>
        `;
        container.appendChild(newRow);
    }

    if (addBtn) {
        addBtn.removeAttribute('onclick');
        addBtn.addEventListener('click', addGrowthRow);
    }

    if (container) {
        container.addEventListener('click', e => {
            const trash = e.target.closest('.remove-row-btn');
            if (trash) {
                removeRow(trash);
            }
        });
    }

    // Expose for compatibility if any inline handler executes before removal
    window.addGrowthRow = addGrowthRow;
    window.removeRow = removeRow;
})();
