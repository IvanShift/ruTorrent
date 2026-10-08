import { readFileSync } from "fs";

window.$ = require("jquery");

// rTorrent keeps a download's priority, state, file priorities, tracker
// switches, ratio group, throttle channel and custom fields in its session
// files and writes them only when something saves the download. After a
// change from the WebUI only its periodic session.save did that, so a crash
// or `docker kill` before it lost the change (R4-NF-2, as ACC-OWN-A-02 for the
// label). Every action that changes such a setting now saves the downloads it
// changed, after the change and in the same request:
// rTorrentStub.prototype.saveSessions(), the client half of
// rXMLRPCRequest::addSessionSaves(). Without httprpc the request goes to
// /RPC2; with it, the server modes add the saves (HttprpcSessionSaveTest).

function load(source, prefix = "", suffix = "") {
  const script = document.createElement("script");
  script.textContent = prefix + readFileSync(source, "utf8") + suffix;
  document.body.appendChild(script);
}

function loadUI(httprpc) {
  window.theWebUI = {
    settings: { "webui.needmessage": true },
    showFlags: 0xffff,
    systemInfo: { rTorrent: { apiVersion: 24, iVersion: 0x1018, started: true } },
    torrents: {},
    config: () => {},
    createMenu: () => {},
    setSettings: () => {},
    addAndShowSettings: () => {},
  };
  for (const source of ["../lang/en.js", "../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(source);
  correctContent();
  if (httprpc !== null)
    load("../plugins/httprpc/init.js",
      `(function () { var plugin = { enabled: ${httprpc} };\n`, "\n})();");
}

// A bundled plugin's init.js, run the way the plugin loader runs it: in a
// closure holding a `plugin` that may change the menu and nothing else.
function loadPlugin(name) {
  window.__plugin = new Proxy({ enabled: true, canChangeMenu: () => true }, {
    get: (target, prop) => (prop in target ? target[prop] : () => false),
    set: (target, prop, value) => { target[prop] = value; return true; },
  });
  load(`../plugins/${name}/init.js`, "(function () { var plugin = window.__plugin;\n", "\n})();");
}

const hashOf = (n) => n.toString(16).toUpperCase().padStart(40, "0");
const [A, B] = [hashOf(1), hashOf(2)];

// The members of the request the stub built: method(first argument).
function members(content) {
  const found = [];
  const member = /<name>methodName<\/name><value><string>([^<]*)<\/string><\/value><\/member><member><name>params<\/name><value><array><data><value><string>([^<]*)<\/string>/g;
  let match;
  while ((match = member.exec(content)) !== null)
    found.push(`${match[1]}(${match[2]})`);
  return found;
}

const saves = (hashes) => hashes.map((hash) => `d.save_resume(${hash})`);

describe.each([
  ["without httprpc init", null],
  ["with httprpc disabled", false],
])("an action %s", (_name, httprpc) => {
  beforeEach(() => loadUI(httprpc));

  it.each([
    ["dsetprio", `?action=dsetprio&hash=${A}&hash=${B}&v=3&list=1`, [A, B]],
    ["start", `?action=start&hash=${A}&hash=${B}&list=1`, [A, B]],
    ["stop", `?action=stop&hash=${A}&hash=${B}&list=1`, [A, B]],
    ["pause", `?action=pause&hash=${A}&list=1`, [A]],
    ["unpause", `?action=unpause&hash=${A}&list=1`, [A]],
    ["setprioritize", `?action=setprioritize&hash=${A}&v=0&v=1&s=1`, [A]],
  ])("%s ends with one save per download it changed", (_action, uri, hashes) => {
    const stub = new rTorrentStub(uri);
    const sent = members(stub.content);
    expect(sent.slice(-hashes.length)).toEqual(saves(hashes));
    expect(sent.filter((member) => member.startsWith("d.save_resume("))).toEqual(saves(hashes));
    expect(sent.length).toBeGreaterThan(hashes.length);
  });

  it.each([
    ["setprio", `?action=setprio&hash=${A}&v=0&v=1&s=2`, "setprio"],
    ["settrackerstate", `?action=settrackerstate&hash=${A}&v=0&s=1`, "trkstate"],
  ])("%s goes to the trusted server mode, which adds the save", (action, uri, mode) => {
    // /RPC2 refuses f.priority.set beside d.update_priorities, and on 0.9.8
    // t.is_enabled.set, so neither can carry the trusted save there.
    const stub = new rTorrentStub(uri);
    expect(stub.mountPoint).toBe("plugins/httprpc/action.php");
    expect(stub.contentType).toBe("application/x-www-form-urlencoded");
    expect(stub.content.startsWith(`mode=${mode}&hash=${A}`)).toBe(true);
    expect(stub.commands).toHaveLength(0);
  });

  it.each([
    ["updateTracker", `?action=updateTracker&hash=${A}`],
    ["ban", `?action=ban&hash=${A}&v=PEER1`],
    ["kick", `?action=kick&hash=${A}&v=PEER1`],
  ])("%s changes nothing kept in the session and sends no save", (_action, uri) => {
    const stub = new rTorrentStub(uri);
    expect(stub.content).not.toContain("d.save_resume");
  });
});

describe("bundled plugins", () => {
  beforeEach(() => loadUI(null));

  it("ratio: a new ratio group is saved after the move", () => {
    loadPlugin("ratio");
    theWebUI.torrents[A] = { ratiogroup: "rat_0" };
    const stub = new rTorrentStub(`?action=setratio&hash=${A}&v=1&list=1`);
    const sent = members(stub.content);
    expect(sent).toEqual([
      `view.set_not_visible(${A})`, `d.views.remove(${A})`,
      `d.views.push_back_unique(${A})`, `view.set_visible(${A})`,
      `d.save_resume(${A})`,
    ]);
  });

  it("ratio: a group the download is already in sends nothing", () => {
    loadPlugin("ratio");
    theWebUI.torrents[A] = { ratiogroup: "rat_1" };
    const stub = new rTorrentStub(`?action=setratio&hash=${A}&v=1&list=1`);
    expect(stub.commands).toHaveLength(0);
  });

  it("scheduler: ignoring the schedule is saved after the change", () => {
    loadPlugin("scheduler");
    theWebUI.torrents[A] = { status: theUILang.Seeding };
    const stub = new rTorrentStub(`?action=schignore&hash=${A}&s=1&list=1`);
    const sent = members(stub.content);
    expect(sent[sent.length - 1]).toBe(`d.save_resume(${A})`);
    expect(sent.filter((member) => member.startsWith("d.save_resume("))).toHaveLength(1);
    expect(sent).toContain(`${theRequestManager.map("d.set_custom")}(${A})`);
  });
});
