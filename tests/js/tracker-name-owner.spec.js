import { readFileSync } from 'fs';

window.$ = window.jQuery = require('jquery');
window.$type = value => value == null ? null : typeof value;

const pluginNames = ['history', 'tracklabels', 'mobile'];
const cases = [
  ['http://sub.example.com/announce', 'example.com'],
  ['udp://tr.foo.co.uk:80/announce', 'foo.co.uk'],
  ['http://1.2.3.4:80/announce', '1.2.3.4'],
  ['http://user:pass@sub.example.com/a', 'example.com'],
  ['tracker.example.com/announce', ''],
  ['http:/tracker.example.com/announce', ''],
  ['', ''],
];

function loadCore() {
  window.theUILang = new Proxy({}, { get: (_target, prop) => prop });
  window.theFormatter = {};
  window.TYPE_STRING = 'string';
  window.TYPE_NUMBER = 'number';
  window.TYPE_PROGRESS = 'progress';
  window.TYPE_PEERS = 'peers';
  window.TYPE_SEEDS = 'seeds';
  window.ALIGN_RIGHT = 'right';
  window.dxSTable = function() {};
  window.rSpeedGraph = function() {};
  window.rSpeedGraph.prototype.addData = () => {};
  window.Timer = function() {};
  window.dStatus = { started: 1, paused: 2, checking: 4, hashing: 8, error: 16 };
  const source = readFileSync('../js/webui.js', 'utf8').replace(
    /\n\$\(document\)\.ready\(function\(\)\n\{[\s\S]*?\n\}\);\s*$/, '');
  const script = document.createElement('script');
  script.textContent = source;
  document.body.appendChild(script);
  theWebUI.categoryList = {
    refreshPanel: { plabel() {} },
    contextMenuEntries() { return []; },
    updatedStatisticEntry() { return []; },
    syncFn() {},
  };
  window.rTorrentStub = function() {};
  window.thePlugins = { get: () => null };
  window.theTabs = { onShow() {} };
}

function loadPlugin(name, hidden = []) {
  const plugin = {
    hideTrackers: hidden,
    loadLang() {},
    loadMainCSS() {},
    canChangeOptions: () => false,
    canChangeTabs: () => name === 'history',
    canChangeColumns: () => false,
    canChangeMenu: () => false,
  };
  window.__trackerNamePlugin = plugin;
  let source = readFileSync(`../plugins/${name}/init.js`, 'utf8');
  if (name === 'mobile')
    source = source.replace(/plugin\.disableOthers\(\);\s*$/, '');
  const script = document.createElement('script');
  script.textContent = `(function () { var plugin = window.__trackerNamePlugin; ${source}\n})();`;
  document.body.appendChild(script);
  delete window.__trackerNamePlugin;
}

beforeEach(() => {
  document.body.innerHTML = '';
  loadCore();
});

test.each(pluginNames)('%s alone uses the core tracker name helper', name => {
  const coreMethod = theWebUI.getTrackerName;
  loadPlugin(name);
  expect(typeof coreMethod).toBe('function');
  expect(theWebUI.getTrackerName).toBe(coreMethod);
  for (const [url, result] of cases)
    expect(theWebUI.getTrackerName(url)).toBe(result);
});

const permutations = [
  ['history', 'tracklabels', 'mobile'],
  ['history', 'mobile', 'tracklabels'],
  ['tracklabels', 'history', 'mobile'],
  ['tracklabels', 'mobile', 'history'],
  ['mobile', 'history', 'tracklabels'],
  ['mobile', 'tracklabels', 'history'],
];
test.each(permutations)('order %s then %s then %s keeps the same helper', (...order) => {
  const coreMethod = theWebUI.getTrackerName;
  for (const name of order)
    loadPlugin(name);
  expect(typeof coreMethod).toBe('function');
  expect(theWebUI.getTrackerName).toBe(coreMethod);
  for (const [url, result] of cases)
    expect(theWebUI.getTrackerName(url)).toBe(result);
});

test('plugins contain no fallback copy of the core name parser', () => {
  for (const name of pluginNames) {
    const source = readFileSync(`../plugins/${name}/init.js`, 'utf8');
    expect(source).not.toMatch(/if\s*\(!\$type\(theWebUI\.getTrackerName\)\)/);
  }
});

test('tracklabels hideTrackers still wraps the core result', () => {
  const coreMethod = theWebUI.getTrackerName;
  loadPlugin('tracklabels', ['example.com']);
  loadPlugin('mobile');
  loadPlugin('history');
  expect(typeof coreMethod).toBe('function');
  expect(theWebUI.getTrackerName).not.toBe(coreMethod);
  expect(theWebUI.getTrackerName('http://sub.example.com/announce')).toBe('');
  expect(theWebUI.getTrackerName('udp://tr.foo.co.uk/announce')).toBe('foo.co.uk');
});
