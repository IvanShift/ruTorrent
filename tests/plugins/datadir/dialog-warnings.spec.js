import { readFileSync } from "fs";

window.$ = require("jquery");

function load(src, prefix = "", suffix = "") {
  const script = document.createElement("script");
  script.textContent = prefix + readFileSync(src, "utf8") + suffix;
  document.body.appendChild(script);
}

// The DataDir answer for an applied change that rTorrent then stopped
// (R3FS-02): action.php sends it as a warning next to an empty error list.
const stopped = { desc: "theUILang.datadirStoppedAfterCheck", prm: "stopped-after-hash-check" };

beforeEach(() => {
  document.body.innerHTML = '<button id="btn_datadir_ok" disabled></button>';
  window.theUILang = {
    datadirSetDirFail: "DataDir plugin: Operation failed",
    datadirStoppedAfterCheck: "DataDir plugin: stopped after its hash check",
  };
  window.theWebUI = {
    settings: {},
    systemInfo: { rTorrent: { apiVersion: 24, iVersion: 0x1018, started: true } },
    torrents: {},
  };
  for (const src of ["../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(src);
  load("../plugins/datadir/init.js",
    "(function () { var plugin = { loadMainCSS() {}, loadLang() {}, canChangeMenu() { return false; } };\n",
    "\n})();");
  window.noty = jest.fn();
  window.theDialogManager = { hide: jest.fn() };
});

test("an applied change that rTorrent stopped is shown as a warning and closes the dialog", () => {
  theWebUI.receiveDataDir({ errors: [], warnings: [stopped] });
  expect(noty.mock.calls).toStrictEqual([
    ["DataDir plugin: stopped after its hash check (stopped-after-hash-check)", "alert"],
  ]);
  expect(theDialogManager.hide).toHaveBeenCalledWith("dlg_datadir");
  expect($("#btn_datadir_ok").prop("disabled")).toBe(false);
});

test("an answer without warnings keeps the error display and the silent success", () => {
  theWebUI.receiveDataDir({ errors: [] });
  expect(noty).not.toHaveBeenCalled();
  expect(theDialogManager.hide).toHaveBeenCalledWith("dlg_datadir");

  theDialogManager.hide.mockClear();
  theWebUI.receiveDataDir({ errors: [{ desc: "theUILang.datadirSetDirFail", prm: "datadir-busy" }] });
  expect(noty.mock.calls).toStrictEqual([["DataDir plugin: Operation failed (datadir-busy)", "error"]]);
  expect(theDialogManager.hide).not.toHaveBeenCalled();
});
