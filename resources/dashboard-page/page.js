(function () {
  'use strict';

  var table = document.getElementById('repositories');

  if (!table) {
    return;
  }

  var headers = table.tHead.rows[0].cells;
  var body = table.tBodies[0];

  function valueOf(row, column, kind) {
    var cell = row.cells[column];
    var value = cell ? cell.getAttribute('data-value') || '' : '';

    if (kind !== 'number') {
      return value.toLowerCase();
    }

    return value === '' ? -Infinity : Number(value);
  }

  function sortBy(column) {
    var header = headers[column];
    var kind = header.getAttribute('data-sort');
    var ascending = header.getAttribute('aria-sort') !== 'ascending';
    var rows = Array.prototype.slice.call(body.rows);

    rows.sort(function (one, other) {
      var a = valueOf(one, column, kind);
      var b = valueOf(other, column, kind);
      var order = a < b ? -1 : a > b ? 1 : 0;

      return ascending ? order : -order;
    });

    Array.prototype.forEach.call(headers, function (each) {
      each.removeAttribute('aria-sort');
    });
    header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
    rows.forEach(function (row) {
      body.appendChild(row);
    });
  }

  Array.prototype.forEach.call(headers, function (header, column) {
    if (!header.hasAttribute('data-sort')) {
      return;
    }

    header.tabIndex = 0;
    header.addEventListener('click', function () {
      sortBy(column);
    });
    header.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        sortBy(column);
      }
    });
  });
}());
