;(function () {
  'use strict'

  // Per-tbody state: { key, dir }
  var _sort = {}

  // ── Inject CSS once ───────────────────────────────────────────────
  var _styled = false
  function ensureCSS() {
    if (_styled) return
    _styled = true
    var s = document.createElement('style')
    s.textContent =
      '.th-sortable{cursor:pointer;user-select:none;white-space:nowrap;transition:color .15s}' +
      '.th-sortable:hover{color:#E8760A}' +
      // Arrow is always orange — full opacity when active, dimmed when idle
      '.th-sortable .sort-icon{display:inline-flex;margin-left:4px;color:#E8760A;opacity:.35;vertical-align:-2px;transition:transform .2s ease,opacity .15s ease}' +
      '.th-sortable.th-sort-asc .sort-icon,.th-sortable.th-sort-desc .sort-icon{opacity:1}' +
      '.th-sortable.th-sort-desc .sort-icon{transform:rotate(180deg)}'
    document.head.appendChild(s)
  }

  var ICON = '<svg class="sort-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><polyline points="6 15 12 9 18 15"/></svg>'

  // ── Public: init ──────────────────────────────────────────────────
  // Call from afterRender, after the table is in the DOM. Wires up any
  // <th data-sort-key="..." data-sort-type="text|date|number"> headers
  // found in the same table as the given tbody.
  // Sets or clears the active sort visual on a <th>: class + ASC/DESC label + tooltip.
  function _setThVisual(th, dir, type) {
    th.classList.remove('th-sort-asc', 'th-sort-desc')
    if (dir === 1 || dir === -1) {
      th.classList.add(dir === 1 ? 'th-sort-asc' : 'th-sort-desc')
      th.title = _nextTip(type || 'text', dir)
    } else {
      th.title = _nextTip(type || 'text', 0)
    }
  }

  // defaultSort: optional { key, type, dir, context }. Applied when no prior sort exists for
  // this tbody, OR when `context` is given and differs from the last call's context — this lets
  // pages that reuse one tbody id across multiple tabs/filters (e.g. patient-appts: All/Today/
  // Approved/Pending/...) each keep their own default instead of inheriting whichever tab was
  // visited first. Omit `context` for single-view tables where the cached sort should just persist
  // across re-renders (e.g. after the table's data refreshes).
  function initSortable(tbodyId, defaultSort) {
    ensureCSS()
    var tbody = document.getElementById(tbodyId)
    if (!tbody) return
    var table = tbody.closest('table')
    if (!table) return

    var st = _sort[tbodyId]
    var contextChanged = !!(defaultSort && st && defaultSort.context !== st.context)
    if (!st || contextChanged) {
      // Use the explicit default if given, otherwise fall back to ascending on the first sortable column
      var auto = defaultSort
      if (!auto) {
        var firstTh = table.querySelector('th[data-sort-key]')
        if (firstTh) auto = { key: firstTh.getAttribute('data-sort-key'), type: firstTh.getAttribute('data-sort-type') || 'text', dir: 1 }
      }
      if (auto) st = _sort[tbodyId] = auto
    }

    table.querySelectorAll('th[data-sort-key]').forEach(function (th) {
      th.classList.add('th-sortable')
      if (!th.querySelector('.sort-icon')) th.insertAdjacentHTML('beforeend', ICON)
      th.onclick = function () { _toggleSort(tbodyId, th) }
      var thKey  = th.getAttribute('data-sort-key')
      var thType = th.getAttribute('data-sort-type') || 'text'
      _setThVisual(th, (st && st.key === thKey) ? st.dir : 0, thType)
    })

    // Re-apply a previously chosen sort (e.g. after the table re-rendered)
    if (st) _applySort(tbodyId, st.key, st.type, st.dir)
  }

  function _toggleSort(tbodyId, th) {
    var key  = th.getAttribute('data-sort-key')
    var type = th.getAttribute('data-sort-type') || 'text'
    var prev = _sort[tbodyId]
    var dir  = (prev && prev.key === key) ? -prev.dir : 1

    var table = th.closest('table')
    table.querySelectorAll('th[data-sort-key]').forEach(function (h) {
      _setThVisual(h, h === th ? dir : 0, h.getAttribute('data-sort-type') || 'text')
    })

    _sort[tbodyId] = { context: prev && prev.context, key: key, type: type, dir: dir }
    _applySort(tbodyId, key, type, dir)
  }

  // Returns a tooltip showing the current sort state and what the next click will do.
  // dir=0 means unsorted (next click = ascending).
  function _nextTip(type, dir) {
    if (dir === 1) {
      return type === 'date'   ? 'Ascending · Click to sort newest first'
           : type === 'number' ? 'Ascending · Click to sort highest first'
           :                     'Ascending · Click to sort Z → A'
    }
    if (dir === -1) {
      return type === 'date'   ? 'Descending · Click to sort oldest first'
           : type === 'number' ? 'Descending · Click to sort lowest first'
           :                     'Descending · Click to sort A → Z'
    }
    // Unsorted
    return   type === 'date'   ? 'Click to sort oldest first'
           : type === 'number' ? 'Click to sort lowest first'
           :                     'Click to sort A → Z'
  }

  function _applySort(tbodyId, key, type, dir) {
    var tbody = document.getElementById(tbodyId)
    if (!tbody) return
    var rows = Array.from(tbody.querySelectorAll('tr[data-search]'))
    rows.sort(function (a, b) {
      var av = _val(a, key, type), bv = _val(b, key, type)
      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir
      return 0
    })
    rows.forEach(function (r) { tbody.appendChild(r) })

    // Let pagination recompute which rows show on the current page
    if (window.refreshPagination) window.refreshPagination(tbodyId)
  }

  function _val(row, key, type) {
    var raw = row.getAttribute('data-sort-' + key) || ''
    if (type === 'number') return parseFloat(raw) || 0
    if (type === 'date')   return raw ? (Date.parse(raw) || 0) : 0
    return raw.toLowerCase()
  }

  window.initSortable = initSortable
})()
