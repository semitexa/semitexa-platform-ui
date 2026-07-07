/*
 * platform.calendar runtime.
 *
 * Discovers `[data-ui-calendar]` shells, opens a held-open EventSource on the
 * events feed for the visible month range (first frame = initial events, later
 * frames = live re-runs the ORM auto-publishes on any create/update/delete),
 * and renders a month grid + selected-day agenda + a create/edit editor.
 * Mutations POST to the save/delete routes. Month-start = Monday.
 */
// ES module: shared helpers arrive through the import map ('platform-ui/*'
// -> fingerprinted URLs) — import order guarantees both are initialized
// before this executes; no manual load-order contract to uphold.
import { esc, fetchJson } from 'platform-ui/core';
import {
  WEEKDAYS, MONTHS, ymd, hm, startOfDay, addDays, startOfMonth,
  mondayIndex, gridDays, localDatetimeValue
} from 'platform-ui/dates';

(function () {
  'use strict';
  window.SemitexaUi = window.SemitexaUi || {};
  if (window.SemitexaUi.calendar) return;
  window.SemitexaUi.calendar = { version: 1 };

  function boot() {
    var nodes = document.querySelectorAll('[data-ui-calendar]');
    for (var i = 0; i < nodes.length; i++) initCalendar(nodes[i]);
  }

  function initCalendar(root) {
    if (root.__uicalBooted) return;
    root.__uicalBooted = true;

    var endpoint = root.getAttribute('data-ui-calendar-endpoint') || '/platform/calendar/events';
    var saveUrl = root.getAttribute('data-ui-calendar-save') || (endpoint + '/save');
    var deleteUrl = root.getAttribute('data-ui-calendar-delete') || (endpoint + '/delete');
    var userId = root.getAttribute('data-ui-calendar-user') || '';

    var S = {
      cursor: startOfMonth(new Date()),
      selected: startOfDay(new Date()),
      events: [],
      source: null,
      editing: null, // null | { id?, ... } while the editor is open
    };

    root.classList.add('uical');
    root.addEventListener('click', onClick);
    root.addEventListener('submit', onSubmit);
    render();
    load();

    // ---- data / live ----
    function rangeParams() {
      var days = gridDays(S.cursor);
      var from = ymd(days[0]) + 'T00:00:00';
      var to = ymd(addDays(days[41], 1)) + 'T00:00:00';
      var qs = 'from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
      if (userId) qs += '&userId=' + encodeURIComponent(userId);
      return qs;
    }
    function applyFrame(text) {
      try {
        var d = JSON.parse(text);
        S.events = (d && d.data) ? d.data : [];
        render();
      } catch (e) { /* ignore malformed frame */ }
    }
    function load() {
      if (S.source) { try { S.source.close(); } catch (e) {} S.source = null; }
      var url = endpoint + '?' + rangeParams();
      var getFallback = function () {
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.text(); }).then(applyFrame).catch(function () {});
      };
      // `data-ui-calendar-live="0"` opts out of the held-open SSE stream and just
      // pulls events once (used by the single-user OS calendar, where the SSE
      // held-open loop's blocking Redis read can deadlock a Swoole worker).
      if (root.getAttribute('data-ui-calendar-live') !== '0' && typeof window.EventSource !== 'undefined') {
        try {
          var src = new EventSource(url, { withCredentials: true });
          var gotData = false;
          S.source = src;
          var onData = function (ev) { gotData = true; applyFrame(ev.data); };
          src.addEventListener('ui.collection.data', onData);
          src.addEventListener('message', onData);
          // If the stream fails before its first frame (e.g. no SSE session in
          // this context), fall back to a plain JSON pull so events still load.
          src.onerror = function () { if (!gotData) { try { src.close(); } catch (e) {} S.source = null; getFallback(); } };
          return;
        } catch (e) { /* fall through to plain GET */ }
      }
      getFallback();
    }

    // ---- render ----
    function eventsForDay(day) {
      var key = ymd(day);
      return S.events.filter(function (e) {
        var s = ymd(new Date(e.starts_at));
        var en = ymd(new Date(e.ends_at));
        return key >= s && key <= en;
      }).sort(function (a, b) { return new Date(a.starts_at) - new Date(b.starts_at); });
    }

    function render() {
      root.innerHTML =
        headHtml() +
        '<div class="uical__body">' + monthHtml() + agendaHtml() + '</div>' +
        (S.editing ? editorHtml() : '');
      var ed = root.querySelector('#uical-title-input');
      if (ed) ed.focus();
    }

    function headHtml() {
      var title = MONTHS[S.cursor.getMonth()] + ' ' + S.cursor.getFullYear();
      return '<div class="uical__head">' +
        '<div class="uical__nav">' +
        '<button type="button" class="uical__btn" data-cal="prev" aria-label="Previous month">‹</button>' +
        '<button type="button" class="uical__btn" data-cal="today">Today</button>' +
        '<button type="button" class="uical__btn" data-cal="next" aria-label="Next month">›</button>' +
        '</div>' +
        '<div class="uical__title">' + esc(title) + '</div>' +
        '<button type="button" class="uical__btn uical__btn--go" data-cal="new">＋ New event</button>' +
        '</div>';
    }

    function monthHtml() {
      var days = gridDays(S.cursor);
      var todayKey = ymd(new Date());
      var selKey = ymd(S.selected);
      var month = S.cursor.getMonth();
      var head = '<div class="uical__weekdays">' +
        WEEKDAYS.map(function (w) { return '<div>' + w + '</div>'; }).join('') + '</div>';
      var cells = days.map(function (day) {
        var key = ymd(day);
        var evs = eventsForDay(day);
        var chips = evs.slice(0, 3).map(function (e) {
          return '<button type="button" class="uical__chip" data-cal="edit" data-id="' + esc(e.id) + '" style="' + chipStyle(e.color) + '">' +
            (e.all_day ? '' : '<span class="uical__chip-t">' + esc(hm(new Date(e.starts_at))) + '</span> ') +
            esc(e.title) + '</button>';
        }).join('');
        var more = evs.length > 3 ? '<div class="uical__more">+' + (evs.length - 3) + ' more</div>' : '';
        var cls = 'uical__day' +
          (day.getMonth() !== month ? ' is-other' : '') +
          (key === todayKey ? ' is-today' : '') +
          (key === selKey ? ' is-selected' : '');
        return '<div class="' + cls + '" data-cal="day" data-day="' + key + '">' +
          '<div class="uical__daynum">' + day.getDate() + '</div>' +
          '<div class="uical__chips">' + chips + more + '</div></div>';
      }).join('');
      return '<div class="uical__month">' + head + '<div class="uical__weeks">' + cells + '</div></div>';
    }

    function agendaHtml() {
      var evs = eventsForDay(S.selected);
      var label = WEEKDAYS[mondayIndex(S.selected)] + ', ' + MONTHS[S.selected.getMonth()] + ' ' + S.selected.getDate();
      var list = evs.length === 0
        ? '<div class="uical__empty">Nothing planned.</div>'
        : evs.map(function (e) {
          var time = e.all_day ? 'All day' : (hm(new Date(e.starts_at)) + '–' + hm(new Date(e.ends_at)));
          return '<button type="button" class="uical__item" data-cal="edit" data-id="' + esc(e.id) + '">' +
            '<span class="uical__dot" style="background:' + esc(colorOf(e.color)) + '"></span>' +
            '<span class="uical__item-time">' + esc(time) + '</span>' +
            '<span class="uical__item-title">' + esc(e.title) + (e.location ? ' · ' + esc(e.location) : '') + '</span></button>';
        }).join('');
      return '<div class="uical__agenda"><div class="uical__agenda-head">' + esc(label) + '</div>' +
        '<div class="uical__agenda-list">' + list + '</div>' +
        '<button type="button" class="uical__btn uical__btn--go uical__agenda-new" data-cal="new">＋ New on this day</button></div>';
    }

    function editorHtml() {
      var e = S.editing;
      return '<div class="uical__overlay" data-cal="cancel-bg">' +
        '<form class="uical__editor" data-cal-editor>' +
        '<div class="uical__editor-head">' + (e.id ? 'Edit event' : 'New event') + '</div>' +
        '<label class="uical__f"><span>Title</span><input id="uical-title-input" name="title" value="' + esc(e.title || '') + '" maxlength="200" required></label>' +
        '<label class="uical__f uical__f--check"><input type="checkbox" name="allDay"' + (e.all_day ? ' checked' : '') + '> All day</label>' +
        '<div class="uical__row">' +
        '<label class="uical__f"><span>Start</span><input type="datetime-local" name="startsAt" value="' + esc(e.startsAt) + '" required></label>' +
        '<label class="uical__f"><span>End</span><input type="datetime-local" name="endsAt" value="' + esc(e.endsAt) + '" required></label>' +
        '</div>' +
        '<label class="uical__f"><span>Location</span><input name="location" value="' + esc(e.location || '') + '" maxlength="200"></label>' +
        '<label class="uical__f"><span>Notes</span><textarea name="notes" rows="2">' + esc(e.notes || '') + '</textarea></label>' +
        '<label class="uical__f"><span>Colour</span><select name="color">' +
        ['blue', 'green', 'amber', 'red', 'violet', 'slate'].map(function (c) {
          return '<option value="' + c + '"' + (e.color === c ? ' selected' : '') + '>' + c + '</option>';
        }).join('') + '</select></label>' +
        '<div class="uical__editor-actions">' +
        (e.id ? '<button type="button" class="uical__btn uical__btn--danger" data-cal="delete" data-id="' + esc(e.id) + '">Delete</button>' : '<span></span>') +
        '<span class="uical__spacer"></span>' +
        '<button type="button" class="uical__btn" data-cal="cancel">Cancel</button>' +
        '<button type="submit" class="uical__btn uical__btn--go">Save</button>' +
        '</div></form></div>';
    }

    function colorOf(c) {
      var map = { blue: '#37b7ff', green: '#34d399', amber: '#f5c451', red: '#ff6b82', violet: '#a78bfa', slate: '#94a3b8' };
      return map[c] || map.blue;
    }
    function chipStyle(c) {
      var col = colorOf(c);
      return 'border-left:3px solid ' + col + ';';
    }

    // ---- interactions ----
    function editorDefaultsFor(day) {
      var start = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 9, 0);
      var end = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 10, 0);
      return { title: '', all_day: false, startsAt: localDatetimeValue(start), endsAt: localDatetimeValue(end), location: '', notes: '', color: 'blue' };
    }
    function openEditFor(id) {
      var e = S.events.filter(function (x) { return x.id === id; })[0];
      if (!e) return;
      S.editing = {
        id: e.id, title: e.title, all_day: !!e.all_day,
        startsAt: localDatetimeValue(new Date(e.starts_at)),
        endsAt: localDatetimeValue(new Date(e.ends_at)),
        location: e.location || '', notes: e.notes || '', color: e.color || 'blue',
      };
      render();
    }

    function onClick(ev) {
      var t = ev.target.closest('[data-cal]');
      if (!t) return;
      var act = t.getAttribute('data-cal');
      if (act === 'prev') { S.cursor = new Date(S.cursor.getFullYear(), S.cursor.getMonth() - 1, 1); render(); load(); }
      else if (act === 'next') { S.cursor = new Date(S.cursor.getFullYear(), S.cursor.getMonth() + 1, 1); render(); load(); }
      else if (act === 'today') { S.cursor = startOfMonth(new Date()); S.selected = startOfDay(new Date()); render(); load(); }
      else if (act === 'day') { S.selected = new Date(t.getAttribute('data-day') + 'T00:00:00'); render(); }
      else if (act === 'new') { S.editing = editorDefaultsFor(S.selected); render(); }
      else if (act === 'edit') { ev.stopPropagation(); openEditFor(t.getAttribute('data-id')); }
      else if (act === 'cancel' || act === 'cancel-bg') { if (act === 'cancel-bg' && ev.target !== t) return; S.editing = null; render(); }
      else if (act === 'delete') { del(t.getAttribute('data-id')); }
    }

    function onSubmit(ev) {
      var form = ev.target.closest('[data-cal-editor]');
      if (!form) return;
      ev.preventDefault();
      var fd = new FormData(form);
      // A datetime-local value is local wall-clock without an offset; convert it
      // to a full instant so the server stores the right UTC time and display
      // round-trips back to the same wall-clock (no timezone drift).
      var toIso = function (v) { var d = new Date(v); return isNaN(d.getTime()) ? v : d.toISOString(); };
      var body = {
        title: (fd.get('title') || '').trim(),
        startsAt: toIso(fd.get('startsAt') || ''),
        endsAt: toIso(fd.get('endsAt') || ''),
        allDay: fd.get('allDay') ? '1' : '0',
        location: fd.get('location') || '',
        notes: fd.get('notes') || '',
        color: fd.get('color') || 'blue',
      };
      if (S.editing && S.editing.id) body.id = S.editing.id;
      if (userId) body.userId = userId;
      if (!body.title) return;
      post(saveUrl, body);
    }

    function del(id) {
      if (!id) return;
      post(deleteUrl, { id: id });
    }

    function post(url, body) {
      // fetchJson sends the X-CSRF-Token header — CsrfListener rejects
      // authenticated writes without it.
      fetchJson(url, { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) {
          if (typeof console !== 'undefined' && console.warn) {
            console.warn('[semitexa-ui] calendar write failed', res.status, res.data);
          }
          return;
        }
        S.editing = null;
        // The SSE stream re-runs on the auto-published invalidation; reload too
        // for immediate feedback if the stream is degraded.
        load();
        render();
      }).catch(function (err) {
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[semitexa-ui] calendar write failed', err);
        }
      });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
