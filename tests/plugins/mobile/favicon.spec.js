import { readFileSync } from 'fs';

window.$ = window.jQuery = require('jquery');
window.$type = value => typeof value;
window.theWebUI = { getTrackerName: () => '' };
window.thePlugins = { get: name => name === 'tracklabels' ? {} : null };
window.theUILang = { All: 'All', No_label: 'No label' };

const requests = [];
class FaviconXHR {
  constructor() { this.headers = {}; this.status = 0; requests.push(this); }
  open(method, url) { this.method = method; this.url = url; }
  setRequestHeader(name, value) { this.headers[name] = value; }
  send(body) { this.body = body; }
  abort() { this.aborted = true; if (this.onloadend) this.onloadend(); }
}
window.XMLHttpRequest = FaviconXHR;

let code = readFileSync('../plugins/mobile/init.js', 'utf8');
code = code.replace(/plugin\.disableOthers\(\);\s*$/, '');
const script = document.createElement('script');
script.textContent = `(function () { var plugin = {}; ${code} })();`;
document.body.appendChild(script);

const mobile = window.mobile;

beforeEach(() => {
  mobile.faviconDisposed = false;
  mobile.faviconTimers = new Map();
  mobile.faviconXHRs = new Map();
  mobile.faviconBusy = Object.create(null);
  mobile.currentPage = 'torrentsList';
});

test('mobile filter warms each uncached tracker through one guarded POST and refreshes its image', () => {
  document.body.innerHTML = '<div id="filterStatusList"></div><div id="filterLabelsList"></div>' +
    '<div id="filterTrackersList"></div>';
  requests.length = 0;
  mobile.statusFilterOptions = [];
  mobile.labelList = [];
  mobile.trackerList = [{ name: 'tracker.test', count: 1 },
    { name: 'other.test', count: 2 }];
  mobile.faviconRequests = new Set();
  mobile.faviconFailures = Object.create(null);
  mobile.faviconVersion = {};

  mobile.renderFilterPage();
  mobile.renderFilterPage();
  expect(requests).toHaveLength(2);
  expect(requests[0].method).toBe('POST');
  expect(requests[0].url).toBe('plugins/tracklabels/action.php');
  expect(requests[0].headers['X-Requested-With']).toBe('XMLHttpRequest');
  expect(requests[0].headers['Content-Type']).toBe('application/x-www-form-urlencoded');
  expect(requests[0].body).toBe('fetch=1&tracker=tracker.test');
  expect(requests[1].body).toBe('fetch=1&tracker=other.test');
  expect($('#filterTrackersList img').first().attr('src')).toBe(
    'plugins/tracklabels/action.php?tracker=tracker.test');

  requests[0].status = 200;
  requests[0].onloadend();
  expect($('#filterTrackersList img').first().attr('src')).toMatch(
    /^plugins\/tracklabels\/action\.php\?tracker=tracker\.test&t=\d+$/);
  const refreshed = $('#filterTrackersList img').first().attr('src');
  expect($('#filterTrackersList img').last().attr('src')).toBe(
    'plugins/tracklabels/action.php?tracker=other.test');
  mobile.renderFilterPage();
  expect($('#filterTrackersList img').first().attr('src')).toBe(refreshed);
  expect(requests).toHaveLength(2);
});

test('mobile retries a failed favicon request after a cooldown, not on every render', () => {
  jest.useFakeTimers();
  try {
    document.body.innerHTML = '<div id="filterStatusList"></div><div id="filterLabelsList"></div>' +
      '<div id="filterTrackersList"></div>';
    requests.length = 0;
    mobile.statusFilterOptions = [];
    mobile.labelList = [];
    mobile.trackerList = [{ name: 'tracker.test', count: 1 }];
    mobile.faviconRequests = new Set();
    mobile.faviconFailures = Object.create(null);
    mobile.faviconVersion = {};

    mobile.renderFilterPage();
    expect(requests).toHaveLength(1);
    requests[0].status = 503;
    requests[0].onloadend();
    mobile.renderFilterPage();
    expect(requests).toHaveLength(1);
    jest.advanceTimersByTime(29999);
    mobile.renderFilterPage();
    expect(requests).toHaveLength(1);
    jest.advanceTimersByTime(1);
    mobile.renderFilterPage();
    expect(requests).toHaveLength(2);
    requests[1].status = 200;
    requests[1].onloadend();
    expect($('#filterTrackersList img').attr('src')).toMatch(/&t=\d+$/);
    mobile.renderFilterPage();
    expect(requests).toHaveLength(2);
  } finally {
    jest.useRealTimers();
  }
});

test('mobile does not retry a permanent favicon miss', () => {
  jest.useFakeTimers();
  try {
    document.body.innerHTML = '<div id="filterStatusList"></div><div id="filterLabelsList"></div>' +
      '<div id="filterTrackersList"></div>';
    requests.length = 0;
    mobile.statusFilterOptions = [];
    mobile.labelList = [];
    mobile.trackerList = [{ name: 'tracker.test', count: 1 }];
    mobile.faviconRequests = new Set();
    mobile.faviconFailures = Object.create(null);
    mobile.faviconVersion = {};
    mobile.renderFilterPage();
    requests[0].status = 404;
    requests[0].onloadend();
    jest.advanceTimersByTime(120000);
    mobile.renderFilterPage();
    expect(requests).toHaveLength(1);
  } finally {
    jest.useRealTimers();
  }
});

test('mobile stops retrying a repeatedly unavailable favicon after three attempts', () => {
  jest.useFakeTimers();
  try {
    document.body.innerHTML = '<div id="filterStatusList"></div><div id="filterLabelsList"></div>' +
      '<div id="filterTrackersList"></div>';
    requests.length = 0;
    mobile.statusFilterOptions = [];
    mobile.labelList = [];
    mobile.trackerList = [{ name: 'tracker.test', count: 1 }];
    mobile.faviconRequests = new Set();
    mobile.faviconFailures = Object.create(null);
    mobile.faviconVersion = {};
    for (let attempt = 0; attempt < 3; attempt++) {
      mobile.renderFilterPage();
      expect(requests).toHaveLength(attempt + 1);
      requests[attempt].status = 503;
      requests[attempt].onloadend();
      jest.advanceTimersByTime(30000);
    }
    mobile.renderFilterPage();
    expect(requests).toHaveLength(3);
  } finally {
    jest.useRealTimers();
  }
});

test('mobile asks again after a busy answer without using up its retries', () => {
  // action.php answers 429 when both fetch slots are taken and writes no marker.
  jest.useFakeTimers();
  const random = jest.spyOn(Math, 'random').mockReturnValue(0);
  try {
    document.body.innerHTML = '<div id="filterStatusList"></div><div id="filterLabelsList"></div>' +
      '<div id="filterTrackersList"></div>';
    requests.length = 0;
    mobile.statusFilterOptions = [];
    mobile.labelList = [];
    mobile.trackerList = [{ name: 'tracker.test', count: 1 }];
    mobile.faviconRequests = new Set();
    mobile.faviconFailures = Object.create(null);
    mobile.faviconBusy = Object.create(null);
    mobile.faviconVersion = {};
    mobile.renderFilterPage();
    requests[0].status = 429;
    requests[0].onloadend();
    jest.advanceTimersByTime(29999);
    mobile.renderFilterPage();
    expect(requests).toHaveLength(1);
    jest.advanceTimersByTime(1);
    const answers = [429, 429, 503, 503, 503];
    answers.forEach((status, index) => {
      mobile.renderFilterPage();
      expect(requests).toHaveLength(index + 2);
      requests[index + 1].status = status;
      requests[index + 1].onloadend();
      jest.advanceTimersByTime(30000);
    });
    mobile.renderFilterPage();
    expect(requests).toHaveLength(answers.length + 1);
  } finally {
    random.mockRestore();
    jest.useRealTimers();
  }
});

test.each([
  { status: 429, spread: 0, delay: 30000 },
  { status: 429, spread: 0.99999, delay: 44999 },
  { status: 503, spread: 0.99999, delay: 30000 },
])('mobile retries HTTP $status after $delay ms while the open filter has no row changes', ({ status, spread, delay }) => {
  jest.useFakeTimers();
  const random = jest.spyOn(Math, 'random').mockReturnValue(spread);
  try {
    document.body.innerHTML = '<div id="filterStatusList"></div><div id="filterLabelsList"></div>' +
      '<div id="filterTrackersList"></div>';
    requests.length = 0;
    mobile.statusFilterOptions = [];
    mobile.labelList = [];
    mobile.trackerList = [{ name: 'idle.test', count: 1 }];
    mobile.faviconRequests = new Set();
    mobile.faviconFailures = Object.create(null);
    mobile.faviconBusy = Object.create(null);
    mobile.faviconVersion = {};
    mobile.currentPage = 'torrentFilter';
    mobile.renderFilterPage();
    requests[0].status = status;
    requests[0].onloadend();
    jest.advanceTimersByTime(delay - 1);
    expect(requests).toHaveLength(1);
    // An idle polling cycle does not render this page again.
    jest.advanceTimersByTime(1);
    expect(requests).toHaveLength(2);
    requests[1].status = 200;
    requests[1].onloadend();
    expect($('#filterTrackersList img').attr('src')).toMatch(/idle\.test&t=\d+$/);
    jest.advanceTimersByTime(120000);
    expect(requests).toHaveLength(2);
  } finally {
    random.mockRestore();
    jest.useRealTimers();
  }
});

test.each(['host removed', 'page left', 'plugin removed', 'tracklabels removed'])('mobile cancels a cooled-down host after %s', change => {
  jest.useFakeTimers();
  let plugins;
  try {
    requests.length = 0;
    mobile.faviconRequests = new Set();
    mobile.faviconFailures = Object.create(null);
    mobile.trackerList = [{ name: 'gone.test', count: 1 }];
    mobile.currentPage = 'torrentFilter';
    mobile.warmTrackerFavicon('gone.test');
    requests[0].status = 503;
    requests[0].onloadend();
    if (change === 'host removed') mobile.trackerList = [];
    if (change === 'page left') mobile.currentPage = 'torrentsList';
    if (change === 'plugin removed') mobile.onRemove?.();
    if (change === 'tracklabels removed') {
      plugins = jest.spyOn(thePlugins, 'get').mockReturnValue({ faviconDisposed: true });
    }
    jest.advanceTimersByTime(30000);
    expect(requests).toHaveLength(1);
    if (change === 'plugin removed') {
      mobile.warmTrackerFavicon('gone.test');
      expect(requests).toHaveLength(1);
    }
  } finally {
    plugins?.mockRestore();
    jest.useRealTimers();
  }
});

test('mobile removal aborts a pending favicon and ignores a late completion', () => {
  requests.length = 0;
  mobile.faviconRequests = new Set();
  mobile.faviconVersion = {};
  mobile.warmTrackerFavicon('removed.test');
  const complete = requests[0].onloadend;
  mobile.onRemove?.();
  requests[0].status = 200;
  complete();
  expect(mobile.faviconVersion['removed.test']).toBeUndefined();
  expect(requests[0].aborted).toBe(true);
});

test('mobile stops automatically retrying after twenty busy cooldowns', () => {
  jest.useFakeTimers();
  const random = jest.spyOn(Math, 'random').mockReturnValue(0);
  try {
    requests.length = 0;
    mobile.faviconRequests = new Set();
    mobile.faviconFailures = Object.create(null);
    mobile.currentPage = 'torrentFilter';
    mobile.trackerList = [{ name: 'busy.test', count: 1 }];
    mobile.warmTrackerFavicon('busy.test');
    for (let attempt = 0; attempt < 21; attempt++) {
      expect(requests).toHaveLength(attempt + 1);
      requests[attempt].status = 429;
      requests[attempt].onloadend();
      jest.advanceTimersByTime(45000);
    }
    mobile.warmTrackerFavicon('busy.test');
    expect(requests).toHaveLength(21);
    expect(mobile.faviconFailures['busy.test']).toBeUndefined();
  } finally {
    random.mockRestore();
    jest.useRealTimers();
  }
});
