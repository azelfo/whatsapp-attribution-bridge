(() => {
  'use strict';

  const config = window.WAB_CONFIG;
  if (!config || !config.endpoint || !config.messages) return;

  const ZERO = '\u200B';
  const ONE = '\u200C';
  const FIRST_KEY = 'wab_first_attribution';
  const LAST_KEY = 'wab_last_attribution';
  const registered = new Set();

  const read = (key) => {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (_) { return null; }
  };

  const write = (key, value) => {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (_) { /* fail open */ }
  };

  const cleanUrl = (value) => {
    if (!value) return '';
    try {
      const url = new URL(value, location.href);
      return `${url.origin}${url.pathname}`.slice(0, 2048);
    } catch (_) {
      return '';
    }
  };

  const capture = () => {
    const query = new URLSearchParams(location.search);
    const data = {
      landing_url: cleanUrl(location.href),
      referrer: cleanUrl(document.referrer),
      captured_at: new Date().toISOString()
    };
    let campaignSignal = false;

    query.forEach((value, key) => {
      if (/^utm_[a-z0-9_]+$/i.test(key) || [
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid',
        'campaign_id', 'ad_group_id', 'ad_id'
      ].includes(key)) {
        data[key.toLowerCase()] = value.slice(0, 512);
        campaignSignal = true;
      }
    });

    const externalReferrer = data.referrer && (() => {
      try { return new URL(data.referrer).hostname !== location.hostname; } catch (_) { return false; }
    })();

    if (!read(FIRST_KEY)) write(FIRST_KEY, data);
    if (campaignSignal || externalReferrer || !read(LAST_KEY)) write(LAST_KEY, data);

    return { first: read(FIRST_KEY) || data, last: read(LAST_KEY) || data };
  };

  const randomToken = () => {
    const bytes = new Uint8Array(6);
    crypto.getRandomValues(bytes);
    return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  };

  const marker = (token) => [...token].map((hex) =>
    parseInt(hex, 16).toString(2).padStart(4, '0')
  ).join('').replace(/[01]/g, (bit) => bit === '0' ? ZERO : ONE);

  const messageIdFrom = (link) => {
    if (link.dataset.wabMessage) return link.dataset.wabMessage;
    try {
      const hash = new URL(link.href, location.href).hash;
      return hash.startsWith('#wab=') ? decodeURIComponent(hash.slice(5)) : '';
    } catch (_) {
      return '';
    }
  };

  const register = (link) => {
    const token = link.dataset.wabToken;
    const messageId = link.dataset.wabMessage;
    if (!token || !messageId || registered.has(token)) return;
    registered.add(token);

    const attribution = capture();
    const body = JSON.stringify({ token, message_id: messageId, ...attribution });
    const blob = new Blob([body], { type: 'application/json' });

    if (!navigator.sendBeacon || !navigator.sendBeacon(config.endpoint, blob)) {
      fetch(config.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        keepalive: true,
        credentials: 'same-origin'
      }).catch(() => {});
    }
  };

  const prepare = (link) => {
    if (!link || link.dataset.wabPrepared === '1') return link;
    const messageId = messageIdFrom(link);
    const item = config.messages[messageId];
    if (!item || !item.phone || !item.message) return link;

    const token = randomToken();
    const hidden = marker(token);
    link.dataset.wabPrepared = '1';
    link.dataset.wabMessage = messageId;
    link.dataset.wabToken = token;
    link.href = `https://wa.me/${item.phone}?text=${encodeURIComponent(hidden + item.message + hidden)}`;
    return link;
  };

  const trackedLink = (target) => {
    const link = target && target.closest ? target.closest('a[href]') : null;
    return link && messageIdFrom(link) ? link : null;
  };

  capture();
  document.querySelectorAll('a[href*="#wab="], a[data-wab-message]').forEach(prepare);
  ['pointerdown', 'click', 'auxclick'].forEach((eventName) => {
    document.addEventListener(eventName, (event) => {
      const link = trackedLink(event.target);
      if (link) register(prepare(link));
    }, true);
  });
})();
