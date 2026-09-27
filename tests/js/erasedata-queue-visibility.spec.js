import { readFileSync } from "fs";

window.$ = require("jquery");

function loadErasedataPlugin() {
  const script = document.createElement("script");
  script.textContent = "(function () { var plugin = { " +
    "loadLang() {}, canChangeMenu() { return false; }, " +
    "addPaneToStatusbar(id, content) { document.body.appendChild($(content).attr('id', id).addClass('status-cell')[0]); }, " +
    "removePaneFromStatusbar(id) { $('#' + id).remove(); }, markLoaded() {} };\n" +
    readFileSync("../plugins/erasedata/init.js", "utf8") +
    "\nwindow.erasedataPlugin = plugin; })();";
  document.body.appendChild(script);
  return window.erasedataPlugin;
}

describe("erasedata queue visibility", () => {
  let status;
  let plugin;
  let ajax;

  beforeEach(() => {
    jest.useFakeTimers().setSystemTime(new Date("2026-09-27T00:00:00Z"));
    document.body.innerHTML = "";
    window.theWebUI = { settings: { "webui.reqtimeout": 1000 } };
    window.noty = jest.fn();
    status = { empty: false, unreadable: false, candidates: 2 };
    ajax = jest.spyOn($, "ajax").mockImplementation(options => {
      options.success(status);
      options.complete();
    });
    plugin = loadErasedataPlugin();
  });

  afterEach(() => {
    plugin.onRemove();
    ajax.mockRestore();
    jest.useRealTimers();
    delete window.erasedataPlugin;
  });

  test("shows a persistent status after five minutes and clears it on recovery", () => {
    expect(noty).not.toHaveBeenCalled();
    jest.advanceTimersByTime(240000);
    expect(noty).not.toHaveBeenCalled();
    jest.advanceTimersByTime(60000);
    expect(noty).toHaveBeenCalledWith(
      "Deletion queue remains nonempty. Check the server log.", "error");
    expect($("#erasedata-queue-status").text()).toContain("remains nonempty");
    expect($("#erasedata-queue-pane").css("display")).not.toBe("none");
    status = { empty: true, unreadable: false, candidates: 0 };
    jest.advanceTimersByTime(60000);
    expect($("#erasedata-queue-status").text()).toBe("");
    expect($("#erasedata-queue-pane").css("display")).toBe("none");
  });

  test("clears a transient status error when a readable nonempty scan returns", () => {
    status = { empty: false, unreadable: true, candidates: 0 };
    jest.advanceTimersByTime(60000);
    expect($("#erasedata-queue-status").text()).toContain("status unavailable");
    status = { empty: false, unreadable: false, candidates: 1 };
    jest.advanceTimersByTime(60000);
    expect($("#erasedata-queue-status").text()).toBe("");
    expect($("#erasedata-queue-pane").css("display")).toBe("none");
    jest.advanceTimersByTime(180000);
    expect($("#erasedata-queue-status").text()).toContain("remains nonempty");
  });

  test("reports an unreadable queue immediately and stops polling on removal", () => {
    status = { empty: false, unreadable: true, candidates: 0 };
    jest.advanceTimersByTime(60000);
    expect(noty).toHaveBeenCalledWith(
      "Deletion queue status unavailable. Inspect the erasedata queue.", "error");
    const requests = ajax.mock.calls.length;
    plugin.onRemove();
    jest.advanceTimersByTime(120000);
    expect(ajax).toHaveBeenCalledTimes(requests);
  });
});
