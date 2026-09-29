import { readFileSync } from "fs";
import vm from "vm";

const LINK = "https://tracker.example/topic?id=1&key=a=b";
const HASH = "A".repeat(40);

function loadExtsearch() {
  const table = {
    rowdata: { "extteg_0$0": {} },
    setValuesById: () => false,
    setIcon: () => false,
    removeRow: jest.fn(),
    correctSelection() {},
    refreshRows() {},
    sortId: false,
  };
  const plugin = {
    enabled: true,
    loadMainCSS() {},
    loadLang() {},
    canChangeOptions: () => false,
  };
  const categoryList = {
    switchLabel() {},
    contextMenuEntries() {},
    refreshAndSyncPanel() {},
    refreshPanel: { psearch: () => [] },
    selection: { ids: () => ["extteg_0"] },
  };
  const theWebUI = {
    categoryList,
    torrents: {},
    getTable: () => table,
    getTorrents: jest.fn(),
    loadTorrents: jest.fn(),
    requestWithTimeout: jest.fn(),
    timeout: jest.fn(),
    error: jest.fn(),
  };
  const rTorrentStub = function () {};
  const context = vm.createContext({
    plugin,
    theWebUI,
    theSearchEngines: { set() {}, show() {}, run() {} },
    rTorrentStub,
    $type: (value) => value == null ? false : typeof value,
    theUILang: { addTorrentPending: "Pending", addTorrentSuccess: "Loaded", addTorrentFailed: "Failed" },
    Date,
    noty: jest.fn(),
    $: () => ({ val: () => "", removeAttr() {} }),
  });
  vm.runInContext(readFileSync("../plugins/extsearch/init.js", "utf8"), context);
  const item = { name: "Search result", src: "Test", link: LINK, hash: "" };
  plugin.tegs.extteg_0 = { data: [item] };
  return { plugin, item, table, theWebUI, rTorrentStub, noty: context.noty };
}

describe("extsearch pending torrent load", () => {
  beforeEach(() => jest.useFakeTimers().setSystemTime(new Date("2026-09-29T00:00:00Z")));
  afterEach(() => jest.useRealTimers());

  test("a normal torrent-list refresh confirms the open pending result", () => {
    const { plugin, item, theWebUI, rTorrentStub } = loadExtsearch();
    theWebUI.setTagsHash({ teg: "extteg_0", data: [{ ndx: 0, hash: null }] });
    expect(item.hash).toBeNull();
    expect(theWebUI.getTorrents).toHaveBeenCalledWith("list=1");

    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(1);
    const [query, [callback, receiver]] = theWebUI.requestWithTimeout.mock.calls[0];
    expect(query).toContain("?action=extsearchhistory&v=" + encodeURIComponent(LINK));
    expect(receiver).toBe(theWebUI);
    const request = { vs: [encodeURIComponent(LINK)] };
    rTorrentStub.prototype.extsearchhistory.call(request);
    expect(request.content).toContain("mode=history&url=" + encodeURIComponent(LINK));
    callback.call(receiver, { [LINK]: HASH });
    expect(item.hash).toBe(HASH);
    expect(theWebUI.getTorrents).toHaveBeenCalledTimes(2);
  });

  test("expired receipt becomes retryable and reports the failure", () => {
    const { item, theWebUI, noty } = loadExtsearch();
    item.hash = null;
    theWebUI.loadTorrents();
    const [, [callback, receiver]] = theWebUI.requestWithTimeout.mock.calls[0];
    callback.call(receiver, { [LINK]: "" });
    expect(item.hash).toBe("");
    expect(noty).toHaveBeenCalledWith("Failed (Search result)", "error");
    expect(theWebUI.getTorrents).not.toHaveBeenCalled();
    jest.advanceTimersByTime(5000);
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(1);
  });

  test("a reply for a replaced search result cannot alter its new pending row", () => {
    const { plugin, item, theWebUI } = loadExtsearch();
    item.hash = null;
    theWebUI.loadTorrents();
    const [, [callback, receiver]] = theWebUI.requestWithTimeout.mock.calls[0];
    const replacement = { ...item };
    plugin.tegs.extteg_0.data = [replacement];
    callback.call(receiver, { [LINK]: HASH });
    expect(replacement.hash).toBeNull();
    expect(item.hash).toBeNull();
    expect(theWebUI.getTorrents).not.toHaveBeenCalled();
  });

  test("a reply from an older load attempt cannot clear a newer pending attempt", () => {
    const { item, theWebUI } = loadExtsearch();
    item.hash = null;
    theWebUI.loadTorrents();
    const [, [callback, receiver]] = theWebUI.requestWithTimeout.mock.calls[0];
    theWebUI.setTagsHash({ teg: "extteg_0", data: [{ ndx: 0, hash: null }] });
    callback.call(receiver, { [LINK]: "" });
    expect(item.hash).toBeNull();
    jest.advanceTimersByTime(5000);
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(2);
  });

  test("deleting a pending result ignores its late status reply", () => {
    const { plugin, item, table, theWebUI, noty } = loadExtsearch();
    item.hash = null;
    theWebUI.loadTorrents();
    const [, [callback, receiver]] = theWebUI.requestWithTimeout.mock.calls[0];
    plugin.tegArray = [{ ndx: 0 }];
    theWebUI.tegItemRemove();
    expect(item.deleted).toBe(true);
    expect(table.removeRow).toHaveBeenCalledWith("extteg_0$0");
    callback.call(receiver, { [LINK]: HASH });
    expect(item.hash).toBeNull();
    expect(noty).not.toHaveBeenCalled();
    expect(theWebUI.getTorrents).not.toHaveBeenCalled();
  });

  test("no selected search result means no pending-history poll", () => {
    const { item, theWebUI } = loadExtsearch();
    item.hash = null;
    theWebUI.categoryList.selection.ids = () => [];
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).not.toHaveBeenCalled();
  });

  test("a pending receipt is polled at most once per refresh interval", () => {
    const { item, theWebUI } = loadExtsearch();
    item.hash = null;
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(1);
    const [, [callback, receiver]] = theWebUI.requestWithTimeout.mock.calls[0];
    callback.call(receiver, { [LINK]: null });
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(1);
    jest.advanceTimersByTime(5000);
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(2);
  });

  test("an unfinished status request does not overlap and a failure can retry", () => {
    const { item, theWebUI } = loadExtsearch();
    item.hash = null;
    theWebUI.loadTorrents();
    const [, , , onError] = theWebUI.requestWithTimeout.mock.calls[0];
    jest.advanceTimersByTime(5000);
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(1);
    onError("500", "Unavailable");
    expect(theWebUI.error).toHaveBeenCalledWith("500", "Unavailable");
    theWebUI.loadTorrents();
    expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(2);
    theWebUI.requestWithTimeout.mock.calls[1][2]();
    expect(theWebUI.timeout).toHaveBeenCalled();
  });
});
