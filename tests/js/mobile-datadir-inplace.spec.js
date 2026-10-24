import { readFileSync } from "fs";

window.$ = require("jquery");
window.jQuery = window.$;

beforeAll(() => {
  for (const file of ["../lang/en.js", "../js/browser.js", "../js/common.js"]) {
    const script = document.createElement("script");
    script.textContent = readFileSync(file, "utf8");
    document.body.appendChild(script);
  }
});


test("mobile DataDir omits the removed fast option while add-torrent keeps its own", () => {
  const html = readFileSync("../plugins/mobile/mobile.html", "utf8");
  const page = new DOMParser().parseFromString(html, "text/html");
  expect(page.getElementById("datadir_fastresume")).toBeNull();
  expect(page.getElementById("fast_resume")).not.toBeNull();

  document.body.innerHTML = '<input id="datadir_edit" value="/data/new">' +
    '<input id="datadir_not_add_path" type="checkbox">' +
    '<input id="datadir_move" type="checkbox" checked>' +
    '<button id="dataDirOk"></button>';
  window.plugin = { disable: jest.fn() };
  window.theWebUI = { getTrackerName: () => "" };
  window.$type = value => typeof value;
  const script = document.createElement("script");
  script.textContent = readFileSync("../plugins/mobile/init.js", "utf8");
  document.body.appendChild(script);

  plugin.torrent = { hash: "A".repeat(40) };
  plugin.dataDirSnapshot = "prior form";
  const ajax = $.ajax;
  $.ajax = jest.fn();
  try {
    plugin.sendDataDir();
    const request = $.ajax.mock.calls[0][0];
    const params = new URLSearchParams(request.data);
    expect(params.get("hash")).toBe("A".repeat(40));
    expect(params.get("move_datafiles")).toBe("1");
    expect(params.has("move_fastresume")).toBe(false);
  } finally {
    $.ajax = ajax;
  }
});

// Answers of plugins/datadir/action.php as the real daemon gave them in lane
// L4's end-to-end run (round3/lanes/L4-fsops-evidence/e2e/out-control/
// result.json, sha256 893ec3ec...): a directory-only change to a directory
// without the data, which rTorrent then stopped after its hash check
// (R3FS-02); a change refused while the profile lock was held; a change
// applied to a stopped download.
const ANSWERS = {
  stoppedAfterCheck: JSON.parse('{"errors":[],"warnings":[{"desc":"theUILang.datadirStoppedAfterCheck","prm":"stopped-after-hash-check"}]}'),
  busy: JSON.parse('{"errors":[{"desc":"theUILang.datadirSetDirFail","prm":"datadir-busy"}]}'),
  applied: JSON.parse('{"errors":[]}'),
};

describe("mobile DataDir answer (R3FS-02)", () => {
  let warning, refusal;

  beforeEach(() => {
    document.body.innerHTML = '<input id="datadir_edit" value="/data/empty">' +
      '<input id="datadir_not_add_path" type="checkbox">' +
      '<input id="datadir_move" type="checkbox">' +
      '<button id="dataDirOk"></button>';
    // plugins/datadir/lang/en.js of r3fix/fsops
    window.theUILang = {
      datadirSetDirFail: "DataDir plugin: Operation failed",
      datadirStoppedAfterCheck: "DataDir plugin: the directory was changed, but rTorrent stopped the download because its hash check did not find the complete data there",
    };
    window.plugin = { disable: jest.fn() };
    window.theWebUI = { getTrackerName: () => "" };
    window.$type = value => typeof value;
    const script = document.createElement("script");
    script.textContent = readFileSync("../plugins/mobile/init.js", "utf8");
    document.body.appendChild(script);
    plugin.torrent = { hash: "A".repeat(40) };
    plugin.dataDirSnapshot = "prior form";
    plugin.showAlert = jest.fn();
    plugin.goBack = jest.fn();
    plugin.update = jest.fn();
    delete window.__datadirRan;
    warning = theUILang.datadirStoppedAfterCheck + " (stopped-after-hash-check)";
    refusal = theUILang.datadirSetDirFail + " (datadir-busy)";
  });

  // Send the form; answer with the request's own callbacks
  function send() {
    const ajax = $.ajax;
    $.ajax = jest.fn();
    try {
      plugin.sendDataDir();
      expect($("#dataDirOk").prop("disabled")).toBe(true);
      return $.ajax.mock.calls[0][0];
    } finally {
      $.ajax = ajax;
    }
  }

  test("a change that rTorrent stopped after its hash check is a warning, and the page returns", () => {
    send().success(ANSWERS.stoppedAfterCheck);

    expect(plugin.showAlert.mock.calls).toEqual([[warning, "alert-warning"]]);
    expect(plugin.goBack).toHaveBeenCalledTimes(1);
    expect(plugin.update).toHaveBeenCalledWith(true);
    expect($("#dataDirOk").prop("disabled")).toBe(false);
  });

  test("an error keeps the page; an applied change returns without a message", () => {
    send().success(ANSWERS.busy);
    expect(plugin.showAlert.mock.calls).toEqual([[refusal, "alert-danger"]]);
    expect(plugin.goBack).not.toHaveBeenCalled();

    plugin.showAlert.mockClear();
    send().success(ANSWERS.applied);
    expect(plugin.showAlert).not.toHaveBeenCalled();
    expect(plugin.goBack).toHaveBeenCalledTimes(1);
  });

  test("a warning next to an error is shown too, and the page stays", () => {
    send().success({ errors: ANSWERS.busy.errors, warnings: ANSWERS.stoppedAfterCheck.warnings });

    expect(plugin.showAlert.mock.calls).toEqual([[warning, "alert-warning"], [refusal, "alert-danger"]]);
    expect(plugin.goBack).not.toHaveBeenCalled();
  });

  test("a failed request is a visible error and the form stays usable", () => {
    // The origin check's 403, a 5xx page, a lost connection
    send().error({ status: 403 }, "error", "Forbidden");
    send().error({ status: 0 }, "timeout", "");

    expect(plugin.showAlert.mock.calls).toEqual([
      [theUILang.datadirSetDirFail + " (HTTP 403)", "alert-danger"],
      [theUILang.datadirSetDirFail + " (timeout)", "alert-danger"],
    ]);
    expect(plugin.goBack).not.toHaveBeenCalled();
    expect($("#dataDirOk").prop("disabled")).toBe(false);
  });

  test("the language key an answer names is looked up, not run", () => {
    send().success({ errors: [{ desc: "window.__datadirRan=1", prm: "" }] });

    expect(window.__datadirRan).toBeUndefined();
    expect(plugin.showAlert.mock.calls).toEqual([["window.__datadirRan=1", "alert-danger"]]);
  });
});
