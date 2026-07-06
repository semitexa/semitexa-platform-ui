/*
 * platform.date-field runtime — enhances a platform.field with a calendar
 * popover. The field stays the source of truth (form binding + submit); this
 * only makes it read-only and, on a pick, writes the value + dispatches
 * `change` so the field's own handlers capture it. Uses the shared date core.
 */
(function () {
  'use strict';
  window.SemitexaUi = window.SemitexaUi || {};
  if (window.SemitexaUi.dateField) return;
  window.SemitexaUi.dateField = { version: 1 };
  var D = window.SemitexaUi.dates;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function boot() {
    var nodes = document.querySelectorAll('[data-ui-date-field]');
    for (var i = 0; i < nodes.length; i++) init(nodes[i]);
  }

  function init(root) {
    if (root.__dfBooted) return;
    root.__dfBooted = true;
    var input = root.querySelector('input');
    if (!input) return;
    var mode = root.getAttribute('data-ui-date-field-mode') || 'date';
    input.readOnly = true;
    input.setAttribute('autocomplete', 'off');
    input.classList.add('uidf__input');

    var pop = null;
    var cursor = null;
    var selected = null;

    input.addEventListener('mousedown', function (e) { e.preventDefault(); toggle(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
    document.addEventListener('mousedown', function (e) { if (pop && !root.contains(e.target)) close(); });
    document.addEventListener('keydown', function (e) { if (pop && e.key === 'Escape') close(); });

    function toggle() { pop ? close() : open(); }
    function open() {
      if (pop) return;
      selected = D.parseLocal(input.value);
      cursor = D.startOfMonth(selected || new Date());
      pop = document.createElement('div');
      pop.className = 'uidf__pop';
      pop.addEventListener('click', onPopClick);
      pop.addEventListener('change', onPopChange);
      root.appendChild(pop);
      render();
    }
    function close() {
      if (pop) { pop.remove(); pop = null; }
    }

    function timeValue() {
      var t = pop && pop.querySelector('[data-df="time"]');
      return (t && t.value) || (selected ? D.hm(selected) : '09:00');
    }

    function render() {
      var days = D.gridDays(cursor);
      var todayKey = D.ymd(new Date());
      var selKey = selected ? D.ymd(selected) : '';
      var head = '<div class="uidf__head">' +
        '<button type="button" class="uidf__nav" data-df="prev" aria-label="Previous month">‹</button>' +
        '<span class="uidf__title">' + esc(D.MONTHS[cursor.getMonth()] + ' ' + cursor.getFullYear()) + '</span>' +
        '<button type="button" class="uidf__nav" data-df="next" aria-label="Next month">›</button>' +
        '</div>';
      var wd = '<div class="uidf__wd">' + D.WEEKDAYS.map(function (w) { return '<div>' + w.charAt(0) + '</div>'; }).join('') + '</div>';
      var cells = days.map(function (d) {
        var key = D.ymd(d);
        var cls = 'uidf__day' +
          (d.getMonth() !== cursor.getMonth() ? ' is-other' : '') +
          (key === todayKey ? ' is-today' : '') +
          (key === selKey ? ' is-sel' : '');
        return '<button type="button" class="' + cls + '" data-df="day" data-d="' + key + '">' + d.getDate() + '</button>';
      }).join('');
      var time = mode === 'datetime'
        ? '<div class="uidf__timerow"><input type="time" class="uidf__time" data-df="time" value="' + esc(timeValueDefault()) + '"></div>'
        : '';
      var foot = '<div class="uidf__foot">' +
        '<button type="button" class="uidf__link" data-df="clear">Clear</button>' +
        '<button type="button" class="uidf__link" data-df="today">Today</button>' +
        (mode === 'datetime' ? '<button type="button" class="uidf__done" data-df="done">Done</button>' : '') +
        '</div>';
      pop.innerHTML = head + wd + '<div class="uidf__grid">' + cells + '</div>' + time + foot;
    }

    function timeValueDefault() {
      return selected ? D.hm(selected) : '09:00';
    }

    function commit(date) {
      input.value = mode === 'datetime' ? D.localDatetimeValue(date) : D.ymd(date);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function withTime(dayDate) {
      if (mode !== 'datetime') return dayDate;
      var t = timeValue().split(':');
      return new Date(dayDate.getFullYear(), dayDate.getMonth(), dayDate.getDate(),
        parseInt(t[0], 10) || 0, parseInt(t[1], 10) || 0);
    }

    function onPopClick(e) {
      var t = e.target.closest('[data-df]');
      if (!t) return;
      var act = t.getAttribute('data-df');
      if (act === 'prev') { cursor = new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1); render(); }
      else if (act === 'next') { cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1); render(); }
      else if (act === 'day') {
        selected = withTime(D.parseLocal(t.getAttribute('data-d')));
        commit(selected);
        if (mode === 'datetime') { render(); } else { close(); }
      }
      else if (act === 'today') {
        selected = withTime(D.startOfDay(new Date()));
        cursor = D.startOfMonth(selected);
        commit(selected);
        if (mode === 'datetime') { render(); } else { close(); }
      }
      else if (act === 'clear') {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        selected = null;
        close();
      }
      else if (act === 'done') { close(); }
    }

    function onPopChange(e) {
      if (e.target && e.target.getAttribute('data-df') === 'time') {
        var base = selected || D.startOfDay(new Date());
        selected = withTime(base);
        commit(selected);
      }
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
