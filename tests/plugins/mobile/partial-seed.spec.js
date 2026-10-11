import { readFileSync } from "fs";

window.$ = window.jQuery = require("jquery");

window.theUILang = {
  Name: "Name", Status: "Status", Size: "Size", Uploaded: "Uploaded",
  Downloaded: "Downloaded", Done: "Done", ETA: "ETA", Ul_speed: "UL",
  Down_speed: "DL", Ratio: "Ratio", addTime: "Added", seedingTime: "Seeding",
};

window.theWebUI = {
  settings: {},
  requestWithoutTimeout: function (url, callback) {
    callback({});
  },
  save: function () {},
};

// $type and friends come from the core, as they do in the browser
const coreEl = document.createElement("script");
coreEl.textContent = readFileSync("../js/common.js", { encoding: "utf-8" });
document.body.appendChild(coreEl);

let code = readFileSync("../plugins/mobile/init.js", { encoding: "utf-8" });
// The takeover call at the end of the file needs the whole desktop UI; this
// spec exercises the row snapshot helper, a plain assignment above it
code = code.replace(/plugin\.disableOthers\(\);\s*$/, "");
const scriptEl = document.createElement("script");
scriptEl.textContent = `(function () { var plugin = {}; plugin.path = "../plugins/mobile/"; ${code} })();`;
document.body.appendChild(scriptEl);

const plugin = window.mobile;

// A torrent as processTorrents() sees it, with only the fields the snapshot
// reads. Everything else is held constant so the flag is the sole variable.
function row(overrides) {
  return Object.assign({
    hash: "A".repeat(40), name: "Some Release 1080p", size: 1024, label: "",
    state: 1, done: 500, ul: 0, dl: 0, eta: -1, ratio: 0, msg: "",
    partially_done: 0,
  }, overrides);
}

describe("mobile plugin, partial seeds", () => {
  // The list redraws a row only when its snapshot changed. partially_done
  // drives both the status text and the status class, and it can flip while
  // every other snapshotted field stays put -- file priorities edited from
  // another client on an idle torrent -- so a snapshot that omitted it would
  // leave the row showing the old status until something else moved.
  it("keeps the partially done flag in the row snapshot", () => {
    const before = plugin.rowSnapshot(row({ partially_done: 0 }));
    const after = plugin.rowSnapshot(row({ partially_done: 1 }));

    expect(before).toHaveProperty("partially_done", 0);
    expect(after).toHaveProperty("partially_done", 1);
    expect(after).not.toEqual(before);
  });

  it("ignores fields the row does not render", () => {
    const before = plugin.rowSnapshot(row({ seedingtime: "100" }));
    const after = plugin.rowSnapshot(row({ seedingtime: "200" }));

    expect(after).toEqual(before);
  });
});

describe("mobile row filters", () => {
  const hash = 'A'.repeat(40);
  beforeEach(() => {
    document.body.innerHTML = '<div id="torrentsList"><div id="list"><table><tbody></tbody></table></div></div>' +
      '<div id="filterStatusList"></div><div id="filterLabelsList"></div><div id="filterTrackersList"></div>';
    window.theWebUI.categoryList = { panelLabelAttribs: { plabel: new Map() } };
    window.theWebUI.getStatusIcon = () => ['', 'Stopped'];
    window.theWebUI.getTrackerName = url => new URL(url).hostname;
    window.dStatus = { started: 1, paused: 2, checking: 4, hashing: 8, error: 16 };
    window.thePlugins = { get: () => null };
    window.theConverter = { bytes: String, speed: String, round: String, time: String };
    window.rTorrentStub = function() { this.content = 'transport'; this.commands = []; };
    plugin.filters = { status: [], label: [], tracker: [] };
    plugin.rowsPrev = {};
    plugin.rowsPrimed = false;
    plugin.trackersCache = null;
    plugin.torrent = undefined;
    plugin.statusFilterOptions = [];
    plugin.showAlert = jest.fn();
    for (const label of Object.keys(plugin.labelIds)) if (label !== '') delete plugin.labelIds[label];
    plugin.nextLabelId = 1;
  });

  it.each(['constructor', '__proto__'])("filters the literal label %s", label => {
    theWebUI.categoryList.panelLabelAttribs.plabel.set('clabel__' + label, { count: 1 });
    plugin.request = (url, callback) => callback({ [hash]: [] });
    plugin.processTorrents({ [hash]: row({ label }) }, true);
    expect(() => plugin.setFilter('label', label)).not.toThrow();
    expect($('#' + hash).css('display')).not.toBe('none');
    expect(typeof plugin.labelIds[label]).toBe('number');
  });

  it("counts and filters a tracker whose host is an inherited object key", () => {
    plugin.request = (url, callback) => callback({ [hash]: [{ name: 'https://constructor/announce' }] });
    plugin.processTorrents({ [hash]: row({}) }, true);
    expect(plugin.trackerList).toEqual([{ name: 'constructor', count: 1 }]);
    expect(() => plugin.setFilter('tracker', 'constructor')).not.toThrow();
    expect(typeof plugin.trackerIds.constructor).toBe('number');
    expect($('#' + hash).css('display')).not.toBe('none');
  });

  it("refreshes tracker edits on an unchanged hash set through the existing polling loop", () => {
    jest.useFakeTimers();
    try {
      jest.setSystemTime(100000);
      let host = 'old.test';
      const fetches = [];
      plugin.request = (url, callback) => {
        fetches.push(url);
        callback({ [hash]: [{ name: 'https://' + host + '/announce' }] });
      };
      const torrents = { [hash]: row({}) };
      plugin.processTorrents(torrents, true);
      plugin.setFilter('tracker', 'old.test');
      const oldId = plugin.trackerIds['old.test'];
      host = 'new.test';
      jest.advanceTimersByTime(59999);
      plugin.processTorrents(torrents, false);
      expect(fetches).toHaveLength(1);
      jest.advanceTimersByTime(1);
      plugin.processTorrents(torrents, false);
      expect(fetches).toHaveLength(2);
      expect(plugin.trackerList).toEqual([{ name: 'new.test', count: 1 }]);
      expect(plugin.filters.tracker).toEqual([]);
      expect($('#' + hash).hasClass('tracker' + oldId)).toBe(false);
      plugin.setFilter('tracker', 'new.test');
      expect($('#' + hash).css('display')).not.toBe('none');
    } finally {
      jest.useRealTimers();
    }
  });

  it("keeps newer fetched rows and tracker data when an older callback finishes last", () => {
    const callbacks = [];
    plugin.request = (url, callback) => callbacks.push(callback);
    plugin.processTorrents({ [hash]: row({ name: 'Old release', done: 100 }) }, true);
    plugin.processTorrents({ [hash]: row({ name: 'New release', done: 800 }) }, true);
    expect(callbacks).toHaveLength(2);
    const currentTrackers = { [hash]: [{ name: 'https://new.test/announce' }] };
    callbacks[1](currentTrackers);
    const cache = plugin.trackersCache;
    callbacks[0]({ [hash]: [{ name: 'https://old.test/announce' }] });
    expect($('#' + hash + ' h5').text()).toBe('New release');
    expect($('#' + hash + ' .progress-bar').css('width')).toBe('80%');
    expect(plugin.rowsPrev[hash]).toMatchObject({ name: 'New release', done: 800 });
    expect(plugin.trackerList).toEqual([{ name: 'new.test', count: 1 }]);
    expect(plugin.trackersCache).toBe(cache);
    expect(plugin.trackersCache.data).toBe(currentTrackers);
    expect($('#' + hash).hasClass('tracker' + plugin.trackerIds['new.test'])).toBe(true);
  });

  it("keeps newer cached rows when an older tracker callback finishes last", () => {
    const callbacks = [];
    plugin.request = (url, callback) => callbacks.push(callback);
    plugin.processTorrents({ [hash]: row({ name: 'New release', done: 800 }) }, true);
    callbacks[0]({ [hash]: [{ name: 'https://new.test/announce' }] });
    const cache = plugin.trackersCache;
    const removedHash = 'B'.repeat(40);
    plugin.processTorrents({
      [hash]: row({ name: 'Old release', done: 100 }),
      [removedHash]: row({ name: 'Transient torrent' }),
    }, true);
    // The transient torrent disappears before its tracker request completes.
    // Returning to the original hash set reuses the still-fresh tracker cache.
    plugin.processTorrents({ [hash]: row({ name: 'New release', done: 900 }) }, false);
    expect(callbacks).toHaveLength(2);
    callbacks[1]({ [hash]: [{ name: 'https://old.test/announce' }], [removedHash]: [] });
    expect($('#' + hash + ' h5').text()).toBe('New release');
    expect($('#' + hash + ' .progress-bar').css('width')).toBe('90%');
    expect(plugin.rowsPrev[hash]).toMatchObject({ name: 'New release', done: 900 });
    expect(plugin.trackerList).toEqual([{ name: 'new.test', count: 1 }]);
    expect(plugin.trackersCache).toBe(cache);
    expect($('#' + removedHash)).toHaveLength(0);
  });

  it("updates an existing torrent row after a literal name change", () => {
    plugin.request = (url, callback) => callback({ [hash]: [] });
    plugin.processTorrents({ [hash]: row({ name: 'Old release' }) }, true);
    const existing = $('#' + hash)[0];
    const name = 'New <release> & friends';
    plugin.processTorrents({ [hash]: row({ name }) }, false);
    expect($('#' + hash)[0]).toBe(existing);
    expect($('#' + hash + ' h5').text()).toBe(name);
    expect($('#' + hash + ' h5').children()).toHaveLength(0);
    expect(plugin.rowsPrev[hash].name).toBe(name);
  });
});
