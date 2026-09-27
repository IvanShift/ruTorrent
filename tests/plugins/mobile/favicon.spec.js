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
}
window.XMLHttpRequest = FaviconXHR;

let code = readFileSync('../plugins/mobile/init.js', 'utf8');
code = code.replace(/plugin\.disableOthers\(\);\s*$/, '');
const script = document.createElement('script');
script.textContent = `(function () { var plugin = {}; ${code} })();`;
document.body.appendChild(script);

const mobile = window.mobile;

test('mobile filter warms each uncached tracker through one guarded POST and refreshes its image', () => {
  document.body.innerHTML = '<div id="filterStatusList"></div><div id="filterLabelsList"></div>' +
    '<div id="filterTrackersList"></div>';
  requests.length = 0;
  mobile.statusFilterOptions = [];
  mobile.labelList = [];
  mobile.trackerList = [{ name: 'tracker.test', count: 1 },
    { name: 'other.test', count: 2 }];
  mobile.faviconRequests = new Set();
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
