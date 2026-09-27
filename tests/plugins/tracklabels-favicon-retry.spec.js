import { readFileSync } from 'fs';

const requests = [];
class FaviconXHR {
  constructor() { this.status = 0; requests.push(this); }
  open(method, url) { this.method = method; this.url = url; }
  setRequestHeader() {}
  send(body) { this.body = body; }
}

test('desktop retries a failed favicon request after a cooldown, not on every panel refresh', () => {
  jest.useFakeTimers();
  const originalXHR = window.XMLHttpRequest;
  window.XMLHttpRequest = FaviconXHR;
  try {
    const code = readFileSync('../plugins/tracklabels/init.js', 'utf8');
    const start = code.indexOf('plugin.imageURI = function');
    const end = code.indexOf('\nplugin.onLangLoaded', start);
    expect(start).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(start);
    const plugin = { faviconRequests: new Set(), faviconFailures: Object.create(null), imageEditSuffix: { tracker: {} } };
    const catlist = { refreshPanel: { ptrackers() {} }, syncFn() {} };
    const webUI = { update() {} };
    new Function('plugin', 'catlist', 'theWebUI', code.slice(start, end))(plugin, catlist, webUI);

    requests.length = 0;
    const uri = plugin.imageURI('tracker', 'tracker.test');
    expect(uri).toBe('plugins/tracklabels/action.php?tracker=tracker.test');
    expect(requests).toHaveLength(1);
    requests[0].status = 503;
    requests[0].onloadend();
    plugin.imageURI('tracker', 'tracker.test');
    expect(requests).toHaveLength(1);
    jest.advanceTimersByTime(29999);
    plugin.imageURI('tracker', 'tracker.test');
    expect(requests).toHaveLength(1);
    jest.advanceTimersByTime(1);
    plugin.imageURI('tracker', 'tracker.test');
    expect(requests).toHaveLength(2);
  } finally {
    window.XMLHttpRequest = originalXHR;
    jest.useRealTimers();
  }
});

test('desktop does not retry a permanent favicon miss', () => {
  jest.useFakeTimers();
  const originalXHR = window.XMLHttpRequest;
  window.XMLHttpRequest = FaviconXHR;
  try {
    const code = readFileSync('../plugins/tracklabels/init.js', 'utf8');
    const start = code.indexOf('plugin.imageURI = function');
    const end = code.indexOf('\nplugin.onLangLoaded', start);
    const plugin = { faviconRequests: new Set(), faviconFailures: Object.create(null), imageEditSuffix: { tracker: {} } };
    new Function('plugin', 'catlist', 'theWebUI', code.slice(start, end))(
      plugin, { refreshPanel: { ptrackers() {} }, syncFn() {} }, { update() {} });
    requests.length = 0;
    plugin.imageURI('tracker', 'tracker.test');
    requests[0].status = 404;
    requests[0].onloadend();
    jest.advanceTimersByTime(120000);
    plugin.imageURI('tracker', 'tracker.test');
    expect(requests).toHaveLength(1);
  } finally {
    window.XMLHttpRequest = originalXHR;
    jest.useRealTimers();
  }
});

test('desktop stops retrying a repeatedly unavailable favicon after three attempts', () => {
  jest.useFakeTimers();
  const originalXHR = window.XMLHttpRequest;
  window.XMLHttpRequest = FaviconXHR;
  try {
    const code = readFileSync('../plugins/tracklabels/init.js', 'utf8');
    const start = code.indexOf('plugin.imageURI = function');
    const end = code.indexOf('\nplugin.onLangLoaded', start);
    const plugin = { faviconRequests: new Set(), faviconFailures: Object.create(null), imageEditSuffix: { tracker: {} } };
    new Function('plugin', 'catlist', 'theWebUI', code.slice(start, end))(
      plugin, { refreshPanel: { ptrackers() {} }, syncFn() {} }, { update() {} });
    requests.length = 0;
    for (let attempt = 0; attempt < 3; attempt++) {
      plugin.imageURI('tracker', 'tracker.test');
      expect(requests).toHaveLength(attempt + 1);
      requests[attempt].status = 503;
      requests[attempt].onloadend();
      jest.advanceTimersByTime(30000);
    }
    plugin.imageURI('tracker', 'tracker.test');
    expect(requests).toHaveLength(3);
  } finally {
    window.XMLHttpRequest = originalXHR;
    jest.useRealTimers();
  }
});
