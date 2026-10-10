/**
 * Every list in the panel pages the same way: "Showing 1 to 25 of 80",
 * a "Show N entries" choice and page arrows, all under the table, and
 * click-to-sort headings. That is DataTables (datatables.net), served from
 * public/js/vendor/datatables/ so it works without internet and inside the
 * panel's script policy.
 *
 * Searching and exporting are not the table's: the filter bar above each
 * list searches, and each section's Generate Report produces the museum's
 * own branded CSV / Excel / Word / PDF.
 *
 * A table opts in with data-dt. Optional attributes:
 *   data-dt-order="2,desc"   starting sort; without it the server's order stays
 *   data-dt-length="50"      rows per page (default 25)
 * A heading that is empty, or carries data-dt-skip, is an actions column
 * and is not sortable.
 *
 * A row whose only cell spans the table is the server's "nothing here"
 * line. DataTables cannot hold it, so it becomes the empty-table message.
 */
(function () {
  if (!window.DataTable) return;

  function init(table) {
    if (table._dt) return;

    var tbody = table.tBodies[0];
    var head = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1] : null;
    var cols = head ? head.cells.length : 0;
    var empty = 'No entries to show.';
    if (tbody) {
      Array.prototype.slice.call(tbody.rows).forEach(function (row) {
        if (row.cells.length === 1 && cols > 1) {
          empty = row.textContent.replace(/\s+/g, ' ').trim() || empty;
          row.remove();
        }
      });
    }

    var skip = [];
    if (head) {
      Array.prototype.forEach.call(head.cells, function (th, i) {
        if (th.hasAttribute('data-dt-skip') || th.textContent.trim() === '') skip.push(i);
      });
    }

    var order = [];
    if (table.dataset.dtOrder) {
      var o = table.dataset.dtOrder.split(',');
      order = [[parseInt(o[0], 10), (o[1] || 'asc').trim()]];
    }

    table._dt = new DataTable(table, {
      order: order,
      pageLength: parseInt(table.dataset.dtLength || '25', 10),
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      autoWidth: false,
      columnDefs: skip.length ? [{ targets: skip, orderable: false }] : [],
      language: {
        emptyTable: empty,
        zeroRecords: 'No matching entries.',
        lengthMenu: 'Show _MENU_ entries',
        info: 'Showing _START_ to _END_ of _TOTAL_ entries',
        infoEmpty: 'Showing 0 entries',
        infoFiltered: '(filtered from _MAX_)'
      },
      // Searching stays on so a page can narrow the rows from its own
      // filter bar (DataTable.ext.search); the box itself is not drawn.
      layout: {
        topStart: null,
        topEnd: null,
        bottomStart: 'info',
        bottomEnd: ['pageLength', 'paging']
      },
      // "Showing all 6 entries" rather than "Showing 1 to 6 of 6" when there
      // is only the one page.
      infoCallback: function (settings, start, end, max, total, pre) {
        var pages = new DataTable.Api(settings).page.info().pages;
        if (pages > 1 || total === 0) return pre;
        return 'Showing all ' + total + ' ' + (total === 1 ? 'entry' : 'entries') +
          (max !== total ? ' (filtered from ' + max + ')' : '');
      }
    });

    fitFooter(table._dt);
    table._dt.on('draw', function () { fitFooter(table._dt); });
  }

  // "Show 25 entries" beside "Showing all 6", with arrows that all sit
  // disabled, reads as broken. When every row fits on the smallest page the
  // choice is hidden, and the arrows only show when there is a second page.
  var SMALLEST = 10;
  function fitFooter(api) {
    var info = api.page.info();
    var box = api.table().container();
    var length = box.querySelector('.dt-length');
    var paging = box.querySelector('.dt-paging');
    if (length) length.style.display = info.recordsDisplay > SMALLEST ? '' : 'none';
    if (paging) paging.style.display = info.pages > 1 ? '' : 'none';
  }

  window.initDataTables = function (root) {
    (root || document).querySelectorAll('table[data-dt]').forEach(init);
  };
  window.initDataTables();
})();
