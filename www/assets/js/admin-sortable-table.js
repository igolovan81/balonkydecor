document.addEventListener('DOMContentLoaded', function () {
    var tables = document.querySelectorAll('table[data-sortable]');
    if (!tables.length) return;

    tables.forEach(function (table) {
        var tbody = table.querySelector('tbody');
        var buttons = table.querySelectorAll('th .sort-btn');
        if (!tbody || !buttons.length) return;

        buttons.forEach(function (btn) {
            var th = btn.closest('th');
            th.setAttribute('aria-sort', 'none');

            btn.addEventListener('click', function () {
                sortByColumn(table, tbody, buttons, th, btn.dataset.sortType);
            });
        });
    });

    function sortByColumn(table, tbody, buttons, th, type) {
        var headerRow = th.parentNode;
        var colIndex = Array.prototype.indexOf.call(headerRow.children, th);
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';

        buttons.forEach(function (otherBtn) {
            var otherTh = otherBtn.closest('th');
            otherTh.setAttribute('aria-sort', 'none');
            otherTh.classList.remove('sort-asc', 'sort-desc');
        });
        th.setAttribute('aria-sort', dir);
        th.classList.add(dir === 'ascending' ? 'sort-asc' : 'sort-desc');

        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function (rowA, rowB) {
            var valA = cellValue(rowA, colIndex, type);
            var valB = cellValue(rowB, colIndex, type);
            var cmp = type === 'number'
                ? valA - valB
                : String(valA).localeCompare(String(valB), undefined, { sensitivity: 'base' });
            return dir === 'ascending' ? cmp : -cmp;
        });

        rows.forEach(function (row) { tbody.appendChild(row); });
    }

    function cellValue(row, colIndex, type) {
        var cell = row.children[colIndex];
        if (!cell) return type === 'number' ? -Infinity : '';
        var raw = cell.dataset.sortValue !== undefined ? cell.dataset.sortValue : cell.textContent.trim();
        if (type === 'number') {
            var num = parseFloat(raw);
            return isNaN(num) ? -Infinity : num;
        }
        return raw;
    }
});
