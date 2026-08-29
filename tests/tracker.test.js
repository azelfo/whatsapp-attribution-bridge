'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');

const storage = new Map();
const handlers = {};
let beaconCalls = 0;
const link = {
  href: 'https://wa.me/5571999999999?text=Mensagem%20antiga#wab=agendamento',
  dataset: {},
  closest() { return this; }
};

const context = {
  window: {
    WAB_CONFIG: {
      endpoint: 'https://clinica.test/wp-json/wab/v1/click',
      messages: {
        agendamento: { phone: '5571999999999', message: 'Olá! Gostaria de agendar.' }
      }
    }
  },
  location: {
    href: 'https://clinica.test/lp?utm_source=adwords&gclid=TESTE123',
    search: '?utm_source=adwords&gclid=TESTE123',
    hostname: 'clinica.test'
  },
  document: {
    referrer: 'https://google.com/',
    querySelectorAll() { return [link]; },
    addEventListener(name, callback) { handlers[name] = callback; }
  },
  localStorage: {
    getItem(key) { return storage.has(key) ? storage.get(key) : null; },
    setItem(key, value) { storage.set(key, value); }
  },
  navigator: { sendBeacon() { beaconCalls += 1; return true; } },
  crypto: webcrypto,
  URL,
  URLSearchParams,
  Blob,
  fetch: async () => ({ ok: true }),
  console
};

vm.createContext(context);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '..', 'assets', 'tracker.js'), 'utf8'), context);

assert.match(link.dataset.wabToken, /^[a-f0-9]{12}$/);
assert.equal(link.dataset.wabMessage, 'agendamento');
const text = new URL(link.href).searchParams.get('text');
const markerPattern = /^[\u200B\u200C]{48}Olá! Gostaria de agendar\.[\u200B\u200C]{48}$/u;
assert.match(text, markerPattern);
assert.equal(text.slice(0, 48), text.slice(-48));

handlers.pointerdown({ target: link });
handlers.click({ target: link });
assert.equal(beaconCalls, 1, 'o mesmo token deve ser registrado uma única vez');

const first = JSON.parse(storage.get('wab_first_attribution'));
assert.equal(first.utm_source, 'adwords');
assert.equal(first.gclid, 'TESTE123');
assert.equal(first.landing_url, 'https://clinica.test/lp', 'URL armazenada não deve manter query string');

const blockedHandlers = {};
let fetchCalls = 0;
const blockedLink = {
  href: 'https://wa.me/5571999999999?text=Mensagem#wab=agendamento',
  dataset: {},
  closest() { return this; }
};
const blockedContext = {
  window: context.window,
  location: context.location,
  document: {
    referrer: '',
    querySelectorAll() { return [blockedLink]; },
    addEventListener(name, callback) { blockedHandlers[name] = callback; }
  },
  localStorage: {
    getItem() { throw new Error('bloqueado'); },
    setItem() { throw new Error('bloqueado'); }
  },
  navigator: { sendBeacon() { return false; } },
  crypto: webcrypto,
  URL,
  URLSearchParams,
  Blob,
  fetch: async () => { fetchCalls += 1; return { ok: true }; },
  console
};
vm.createContext(blockedContext);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '..', 'assets', 'tracker.js'), 'utf8'), blockedContext);
blockedHandlers.click({ target: blockedLink });
assert.equal(fetchCalls, 1, 'fetch keepalive deve assumir quando sendBeacon falhar');
assert.match(blockedLink.dataset.wabToken, /^[a-f0-9]{12}$/, 'localStorage bloqueado não pode impedir o link');

console.log('tracker.test.js: ok');
