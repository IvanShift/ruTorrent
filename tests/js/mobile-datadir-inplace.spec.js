import { readFileSync } from "fs";

window.$ = require("jquery");
window.jQuery = window.$;

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
