(function() {
    const container = document.getElementById('bookings-tableview');
    if (!container) return;

    Craft.initUiElements(container);

    const form = document.getElementById('bookings-filter-form');
    const {textSelected: selectedText = 'selected', textConfirmDelete: confirmSingle = 'Are you sure?', textConfirmDeleteMultiple: confirmMulti = 'Delete {count} bookings?'} = container.dataset;

    container.querySelectorAll('select.autosubmit').forEach(el => el.addEventListener('change', () => form.submit()));

    document.getElementById('per-page-select')?.addEventListener('change', function() {
        const url = new URL(location.href);
        url.searchParams.set('limit', this.value);
        url.searchParams.delete('page');
        location.href = url;
    });

    document.getElementById('search-clear-btn')?.addEventListener('click', () => {
        document.getElementById('search-input').value = '';
        form.submit();
    });

    const selectAll = document.getElementById('select-all');
    const rowCheckboxes = container.querySelectorAll('.row-checkbox');
    const bulkBar = document.getElementById('bulk-actions-bar');
    const selectedCount = document.getElementById('selected-count');
    const bulkDeleteForm = document.getElementById('bulk-delete-form');
    const bulkDeleteIds = document.getElementById('bulk-delete-ids');
    const singleDeleteForm = document.getElementById('single-delete-form');
    const singleDeleteId = document.getElementById('single-delete-id');

    function syncBulkUI() {
        if (!bulkBar) return;
        const checked = container.querySelectorAll('.row-checkbox:checked');
        bulkBar.classList.toggle('hidden', checked.length === 0);
        if (checked.length) selectedCount.textContent = checked.length + ' ' + selectedText;
        if (selectAll && rowCheckboxes.length) {
            selectAll.checked = checked.length === rowCheckboxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < rowCheckboxes.length;
        }
    }

    selectAll?.addEventListener('change', () => {
        rowCheckboxes.forEach(cb => cb.checked = selectAll.checked);
        syncBulkUI();
    });

    rowCheckboxes.forEach(cb => cb.addEventListener('change', syncBulkUI));

    document.getElementById('clear-selection-btn')?.addEventListener('click', () => {
        rowCheckboxes.forEach(cb => cb.checked = false);
        if (selectAll) selectAll.checked = false;
        syncBulkUI();
    });

    document.getElementById('bulk-delete-btn')?.addEventListener('click', () => {
        const checked = container.querySelectorAll('.row-checkbox:checked');
        if (!checked.length || !confirm(confirmMulti.replace('{count}', checked.length))) return;
        bulkDeleteIds.innerHTML = '';
        checked.forEach(cb => {
            const input = document.createElement('input');
            Object.assign(input, {type: 'hidden', name: 'ids[]', value: cb.value});
            bulkDeleteIds.appendChild(input);
        });
        bulkDeleteForm.submit();
    });

    container.querySelectorAll('.delete-single').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm(confirmSingle)) return;
            singleDeleteId.value = this.dataset.id;
            singleDeleteForm.submit();
        });
    });
})();
