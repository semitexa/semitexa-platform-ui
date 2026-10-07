/*
 * Island runtime (ep-platform-live-state).
 *
 * An island is a component that says what it reads (UiIslandInterface). Its
 * root carries `data-ui-island`, a signed token; this runtime subscribes the
 * island feed, platform-ui.island.feed, with it on the page's KISS stream. A
 * write to what the island reads re-runs the feed, which draws the component
 * again under the same instance id, and the page morphs it in place — focus,
 * an open menu, a typed value survive. A page without a KISS session keeps
 * the island as it was drawn.
 */
import { openFeedChannel, mount } from 'platform-ui/core';

(function () {
  'use strict';
  window.SemitexaUi = window.SemitexaUi || {};
  if (window.SemitexaUi.island) return;
  window.SemitexaUi.island = { version: 1 };

  function apply(root, frame) {
    var data = frame && frame.data;
    if (!data || typeof data.html !== 'string') return;
    var template = document.createElement('template');
    template.innerHTML = data.html;
    var next = template.content.querySelector('[data-ui-island]');
    if (!next || next.getAttribute('data-ui-component-instance-id') !== root.getAttribute('data-ui-component-instance-id')) return;
    var morph = window.SemitexaUi && window.SemitexaUi.morph;
    if (typeof morph === 'function') {
      morph(root, next);
    } else {
      root.replaceWith(next);
    }
  }

  mount('[data-ui-island]', {
    connect: function (root) {
      // The token the page was drawn with: a morph that brings a fresh one
      // must not re-subscribe the island it is already subscribed as.
      var token = root.getAttribute('data-ui-island') || '';
      // `drawn`: the first subscribe tells the server the page already shows
      // this render, so it is not drawn again for nothing. Dropped from the
      // kept params at once: a reconnect re-subscribes without it and gets a
      // fresh render of whatever it missed.
      var params = { island: token, drawn: '1' };
      var channel = openFeedChannel({
        feed: 'platform-ui.island.feed',
        params: params,
        dataEvent: 'ui.collection.data',
        errorEvent: 'ui.collection.error',
        onData: function (frame) { apply(root, frame); },
        onError: function () { /* leave the island as it is */ },
        onPull: function () { /* no live session: the island as drawn */ }
      });
      delete params.drawn;
      return {
        destroy: function () { try { channel.close(); } catch (e) { /* already gone */ } }
      };
    }
  });
})();
