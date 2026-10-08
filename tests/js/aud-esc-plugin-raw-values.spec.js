import { readFileSync } from "fs";

window.$ = require("jquery");
window.jQuery = window.$;

// AUD-ESC-05, plugin part. httprpc now hands the WebUI the daemon's own
// bytes, so these plugins must pass names, paths and tracker URLs through
// untouched: no unescaping of their own, and one encoding on the way out.
// The probe name holds a quote, a backslash, an apostrophe and an
// ampersand; the pairs \\ and \" would change under any extra unescape.
const AUD = "aud \"q\" \\b 'a' &c";
const AUD_PAIRS = AUD + " \\\\x \\\"y";
const HASH = "A".repeat(40);

function inject(path) {
  const script = document.createElement("script");
  script.textContent = readFileSync(path, "utf8");
  document.body.appendChild(script);
}

// The edit dialog markup that theWebUI.editTrackers() fills, with every box
// cleared so the test sees what editTrackers() itself sets.
function editDialog() {
  document.body.innerHTML = '<textarea id="etrackers"></textarea><input id="ecomment">' +
    '<input type="checkbox" id="eset_trackers"><input type="checkbox" id="eset_comment">' +
    '<input type="checkbox" id="eset_private"><select id="eprivate">' +
    '<option value="0">0</option><option value="1">1</option></select>' +
    '<button id="editok" disabled></button>';
}

// Loads plugins/edit/init.js over the given torrents and tracker lists.
function loadEdit(torrents, trackers, selected) {
  window.plugin = { loadMainCSS() {}, loadLang() {}, canChangeMenu: () => false };
  window.rTorrentStub = function () {};
  window.theDialogManager = { show: jest.fn() };
  window.theWebUI = {
    trackers,
    torrents,
    getTable: () => ({ rowSel: selected || {} }),
    isTorrentCommandEnabled: () => true,
  };
  inject("../plugins/edit/init.js");
}

// What the dialog shows, then what its OK button would post.
function editRoundTrip() {
  const shown = {
    trackers: $("#etrackers").val().split(/\r?\n/),
    comment: $("#ecomment").val(),
    setTrackers: $("#eset_trackers").prop("checked"),
    setComment: $("#eset_comment").prop("checked"),
  };
  const stub = { hashes: [HASH] };
  rTorrentStub.prototype.edittorrent.call(stub);
  return { shown, posted: new URLSearchParams(stub.content) };
}

const FIRST = "http://one.test/ann?k=" + AUD_PAIRS;
const SECOND = "udp://two.test:80/ann?k=" + AUD;
const TRACKERS = [
  { name: FIRST, group: 0 },
  { name: "dht://", group: 0 },
  { name: SECOND, group: 1 },
];

test("aud edit dialog shows the tracker URLs and comment in the daemon's own bytes and posts them back byte for byte", () => {
  editDialog();
  loadEdit({ [HASH]: { comment: AUD_PAIRS, private: 0 } }, { [HASH]: TRACKERS });

  theWebUI.editTrackers(HASH);
  expect(theDialogManager.show).toHaveBeenCalledWith("tedit");
  const { shown, posted } = editRoundTrip();
  // The empty line is the tier break that edit/action.php reads back.
  expect(shown).toEqual({ trackers: [FIRST, "", SECOND], comment: AUD_PAIRS,
    setTrackers: true, setComment: true });
  expect(posted.getAll("tracker")).toEqual([FIRST, "", SECOND]);
  expect(posted.get("comment")).toBe(AUD_PAIRS);
  expect(posted.get("set_trackers")).toBe("1");
  expect(posted.get("set_comment")).toBe("1");
  expect(posted.getAll("hash")).toEqual([HASH]);
});

test("aud edit dialog over a selection compares the daemon's own bytes", () => {
  const OTHER = "B".repeat(40);
  // The same trackers and comment on both torrents: the dialog shows them.
  editDialog();
  loadEdit({ [HASH]: { comment: AUD_PAIRS, private: 0 }, [OTHER]: { comment: AUD_PAIRS, private: 0 } },
    { [HASH]: TRACKERS, [OTHER]: TRACKERS.slice() }, { [HASH]: true, [OTHER]: true });
  theWebUI.editTrackers();
  expect(editRoundTrip().shown).toEqual({ trackers: [FIRST, "", SECOND], comment: AUD_PAIRS,
    setTrackers: true, setComment: true });

  // Comments and trackers that differ only in a backslash pair, which an
  // unescape would collapse, are different values: both fields are cleared
  // and their boxes left unchecked.
  const single = AUD_PAIRS.replace("\\\\x", "\\x");
  expect(single).not.toBe(AUD_PAIRS);
  editDialog();
  loadEdit({ [HASH]: { comment: AUD_PAIRS, private: 0 }, [OTHER]: { comment: single, private: 0 } },
    { [HASH]: TRACKERS, [OTHER]: [{ name: "http://one.test/ann?k=" + single, group: 0 }, TRACKERS[2]] },
    { [HASH]: true, [OTHER]: true });
  theWebUI.editTrackers();
  expect(editRoundTrip().shown).toEqual({ trackers: [""], comment: "",
    setTrackers: false, setComment: false });
});

test("aud bulk_magnet copies dn and tr in the daemon's own bytes", () => {
  document.body.innerHTML = "";
  window.plugin = { loadMainCSS() {}, loadLang() {}, canChangeMenu: () => true };
  window.rTorrentStub = function () {};
  const tracker = "http://one.test/ann?k=" + AUD_PAIRS;
  window.theWebUI = {
    createMenu() {},
    torrents: { [HASH]: { name: AUD_PAIRS } },
    trackers: { [HASH]: [{ name: tracker }, { name: "dht://" }] },
  };
  inject("../plugins/bulk_magnet/init.js");

  expect(plugin.getTorrentValue(HASH, "name")).toBe(AUD_PAIRS);
  const magnet = plugin.getTorrentValue(HASH, "magnet");
  expect(magnet.startsWith("magnet:?xt=urn:btih:" + HASH + "&")).toBe(true);
  const params = new URLSearchParams(magnet.slice("magnet:?".length));
  expect(params.get("dn")).toBe(AUD_PAIRS);
  expect(params.getAll("tr")).toEqual([tracker]);
});

test("aud mobile datadir is prefilled with the raw save path and posts the edited path encoded once", () => {
  document.body.innerHTML = '<div id="torrentDataDir"><input id="datadir_edit">' +
    '<button id="showGetDirDataDir"></button></div>' +
    '<input id="datadir_not_add_path" type="checkbox">' +
    '<input id="datadir_move" type="checkbox"><button id="dataDirOk"></button>';
  window.plugin = { disable: jest.fn() };
  window.theWebUI = { getTrackerName: () => "" };
  window.$type = value => typeof value;
  inject("../plugins/mobile/init.js");

  const savePath = "/data/" + AUD_PAIRS;
  plugin.torrent = { hash: HASH, save_path: savePath, multi_file: "1" };
  plugin.showPage = jest.fn();
  plugin.showDataDir();
  expect($("#datadir_edit").val()).toBe(savePath);

  // A changed form is sent; the field travels percent-encoded once.
  const target = savePath + "/" + AUD;
  $("#datadir_edit").val(target);
  const ajax = $.ajax;
  $.ajax = jest.fn();
  try {
    plugin.sendDataDir();
    const params = new URLSearchParams($.ajax.mock.calls[0][0].data);
    expect(params.get("hash")).toBe(HASH);
    expect(params.get("datadir")).toBe(target);
  } finally {
    $.ajax = ajax;
  }
});
