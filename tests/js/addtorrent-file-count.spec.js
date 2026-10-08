import { readFileSync } from "fs";

// PHP keeps the first max_file_uploads file parts of a POST and drops the rest
// before php/addtorrent.php runs, without counting them. The add dialog sends
// the number of files the user selected, so the answer can name the files
// that never arrived (R3C-03).

window.$ = window.jQuery = require("jquery");
// jsdom has no ResizeObserver; theDialogManager only observes dialog sizes.
window.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
window.theWebUI = {
  settings: {},
  version: "test",
  save: () => {},
  upRateMenu: () => {},
  downRateMenu: () => {},
};
// makeContent() ends by building the settings window, which this test leaves out.
window.theOptionsWindow = { init: () => {} };
document.body.innerHTML = '<div id="dialog-container"></div>';
for (const src of ["../lang/en.js", "../js/common.js", "../js/objects.js", "../js/content.js"]) {
  const scriptEl = document.createElement("script");
  scriptEl.textContent = readFileSync(src, { encoding: "utf-8" });
  document.body.appendChild(scriptEl);
}
makeContent();

/** Make the dialog's file input hold files with these names, as a browser would. */
function select(names) {
  const input = document.getElementById("torrent_file");
  const files = names.map((name) => new File(["d8:announce0:e"], name));
  Object.defineProperty(input, "files", {
    configurable: true,
    value: Object.assign(Object.create(null), files, { length: files.length }),
  });
  Object.defineProperty(input, "value", {
    configurable: true,
    get: () => (names.length ? "C:\\fakepath\\" + names[0] : ""),
  });
}

/** Run the form's own submit handler and return its verdict and action. */
function submit(id) {
  const verdict = $("#" + id).triggerHandler("submit");
  return { verdict, action: document.getElementById(id).getAttribute("action") };
}

describe("the add dialog tells addtorrent.php how many files it selected", () => {
  beforeEach(() => {
    $("#torrents_start_stopped").prop("checked", false);
    $("#dir_edit").val("");
    $("#tadd_label").val("");
  });

  it("sends the number of selected files with the upload", () => {
    select(["one.torrent", "two.torrent", "three.torrent"]);
    const { verdict, action } = submit("addtorrent");
    expect(verdict).toBe(true);
    expect(new URLSearchParams(action.split("?")[1]).get("torrent_file_count")).toBe("3");
  });

  it("keeps the other options next to the count", () => {
    select(["one.torrent"]);
    $("#torrents_start_stopped").prop("checked", true);
    $("#tadd_label").val("seed box");
    const query = new URLSearchParams(submit("addtorrent").action.split("?")[1]);
    expect(query.get("torrent_file_count")).toBe("1");
    expect(query.get("torrents_start_stopped")).toBe("1");
    expect(query.get("label")).toBe("seed box");
  });

  it("does not send a count with a URL", () => {
    $("#url").val("magnet:?xt=urn:btih:0123456789ABCDEF0123456789ABCDEF01234567");
    const { verdict, action } = submit("addtorrenturl");
    expect(verdict).toBe(true);
    expect(action).not.toContain("torrent_file_count");
  });
});
