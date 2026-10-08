import { readFileSync } from "fs";

window.$ = require("jquery");

// plugins/httprpc/init.js replaces rTorrentStub actions with its server
// modes, and getCommon(name) falls back to plugin["orig" + name] while the
// plugin is disabled. Two originals were kept under another spelling
// (origupdateTracker, origaddpeer), so with httprpc disabled "Update tracker"
// and "Add peer" threw a TypeError instead of sending their command.

function load(source, prefix = "", suffix = "") {
  const script = document.createElement("script");
  script.textContent = prefix + readFileSync(source, "utf8") + suffix;
  document.body.appendChild(script);
}

beforeAll(() => {
  window.theWebUI = {
    settings: { "webui.needmessage": true },
    showFlags: 0xffff,
    systemInfo: { rTorrent: { apiVersion: 24, iVersion: 0x1018, started: true } },
  };
  for (const source of ["../lang/en.js", "../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(source);
  correctContent();
  load("../plugins/httprpc/init.js",
    "(function () { var plugin = window.__httprpc = { enabled: false };\n", "\n})();");
});

const hash = "A".repeat(40);

test("every server mode the plugin names has its original to fall back to", () => {
  // getchunks is plugins/chunks' action, which the loader runs before httprpc.
  const names = [...readFileSync("../plugins/httprpc/init.js", "utf8")
    .matchAll(/getCommon\("([a-zA-Z_]+)"\)/g)].map((match) => match[1])
    .filter((name) => name !== "getchunks");
  expect(names.length).toBeGreaterThan(20);
  for (const name of names)
    expect([name, typeof window.__httprpc["orig" + name]]).toEqual([name, "function"]);
});

test.each([
  ["updateTracker", `?action=updateTracker&hash=${hash}`, "d.tracker_announce"],
  ["addpeer", `?action=addpeer&hash=${hash}&v=10.0.0.1%3A6881`, "add_peer"],
])("with httprpc disabled %s sends its own command", (_action, uri, method) => {
  const stub = new rTorrentStub(uri);
  expect(stub.content).toContain(`<methodName>${method}</methodName>`);
});
