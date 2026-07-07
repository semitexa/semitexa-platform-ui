/*
 * Shared calendar date utilities — the common month-grid + date math used by
 * BOTH the events calendar (calendar-runtime.js) and the date-field picker
 * (date-field-runtime.js). Monday-based weeks.
 *
 * Dual-mode ES module: importable as 'platform-ui/dates' (server-generated
 * import map -> fingerprinted URL) with named exports, AND still populating
 * the window.SemitexaUi.dates shim for any remaining classic consumer.
 */
(function () {
  'use strict';
  window.SemitexaUi = window.SemitexaUi || {};
  if (window.SemitexaUi.dates) return;

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function hm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addDays(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
  function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
  // Monday-based weekday index (Mon=0 … Sun=6).
  function mondayIndex(d) { return (d.getDay() + 6) % 7; }
  // The 42-day (6-week) grid covering the month of `cursor`.
  function gridDays(cursor) {
    var first = startOfMonth(cursor);
    var start = addDays(first, -mondayIndex(first));
    var out = [];
    for (var i = 0; i < 42; i++) out.push(addDays(start, i));
    return out;
  }
  // "YYYY-MM-DDTHH:MM" local wall-clock (datetime-local shape).
  function localDatetimeValue(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
      'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  // Parse a local date/datetime string ("YYYY-MM-DD" or "YYYY-MM-DDTHH:MM")
  // into a Date, or null when unusable.
  function parseLocal(s) {
    if (!s) return null;
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
    if (!m) { var d = new Date(s); return isNaN(d.getTime()) ? null : d; }
    return new Date(+m[1], +m[2] - 1, +m[3], m[4] ? +m[4] : 0, m[5] ? +m[5] : 0);
  }

  window.SemitexaUi.dates = {
    WEEKDAYS: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    MONTHS: ['January', 'February', 'March', 'April', 'May', 'June', 'July',
      'August', 'September', 'October', 'November', 'December'],
    pad: pad, ymd: ymd, hm: hm, startOfDay: startOfDay, addDays: addDays,
    startOfMonth: startOfMonth, mondayIndex: mondayIndex, gridDays: gridDays,
    localDatetimeValue: localDatetimeValue, parseLocal: parseLocal,
  };
})();

/* ESM surface — same objects the window shim exposes. */
const __dates = window.SemitexaUi.dates;
export const WEEKDAYS = __dates.WEEKDAYS;
export const MONTHS = __dates.MONTHS;
export const pad = __dates.pad;
export const ymd = __dates.ymd;
export const hm = __dates.hm;
export const startOfDay = __dates.startOfDay;
export const addDays = __dates.addDays;
export const startOfMonth = __dates.startOfMonth;
export const mondayIndex = __dates.mondayIndex;
export const gridDays = __dates.gridDays;
export const localDatetimeValue = __dates.localDatetimeValue;
export const parseLocal = __dates.parseLocal;
