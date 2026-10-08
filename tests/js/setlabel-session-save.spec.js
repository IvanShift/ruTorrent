import { readFileSync } from "fs";

window.$ = require("jquery");

// rTorrent writes a download's label (d.custom1) to its session only when it
// saves that download; until then only its periodic session.save did, so a
// crash or `docker kill` a few seconds after a relabel lost it (ACC-OWN-A-02).
// The label request therefore asks rTorrent to save each relabelled download
// with d.save_resume -- what session.save runs per download -- after the
// labels. With httprpc the server builds that request (HttprpcSetLabelSaveTest);
// without it the WebUI builds it here and sends it to /RPC2.

function load(source, prefix = "", suffix = "") {
  const script = document.createElement("script");
  script.textContent = prefix + readFileSync(source, "utf8") + suffix;
  document.body.appendChild(script);
}

function loadUI(httprpc, apiVersion = 24) {
  window.theWebUI = {
    settings: { "webui.needmessage": true },
    showFlags: 0xffff,
    systemInfo: { rTorrent: { apiVersion, iVersion: 0x1018, started: true } },
  };
  for (const source of ["../lang/en.js", "../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(source);
  correctContent();
  if (httprpc !== null)
    load("../plugins/httprpc/init.js",
      `(function () { var plugin = { enabled: ${httprpc} };\n`, "\n})();");
}

const hashOf = (n) => n.toString(16).toUpperCase().padStart(40, "0");

// The request js/webui.js setLabel() makes for these downloads.
function labelURI(hashes, label) {
  return "?action=setlabel" +
    hashes.map((hash) => "&hash=" + hash + "&s=label&v=" + encodeURIComponent(label)).join("") +
    "&list=1";
}

// The members of the XMLRPC request the stub built: method(first argument).
function members(content) {
  const found = [];
  const member = /<name>methodName<\/name><value><string>([^<]*)<\/string><\/value><\/member><member><name>params<\/name><value><array><data><value><string>([^<]*)<\/string>/g;
  let match;
  while ((match = member.exec(content)) !== null)
    found.push(`${match[1]}(${match[2]})`);
  return found;
}

describe.each([
  ["without httprpc init", null],
  ["with httprpc disabled", false],
])("a label set %s", (_name, httprpc) => {
  beforeEach(() => loadUI(httprpc));

  it("labels each download and then saves each one, in one multicall", () => {
    const [a, b] = [hashOf(1), hashOf(2)];
    const stub = new rTorrentStub(labelURI([a, b], "tv shows"));
    const set = theRequestManager.map("d.set_custom1");
    expect(stub.content).toContain("<methodName>system.multicall</methodName>");
    expect(members(stub.content)).toEqual([
      `${set}(${a})`, `${set}(${b})`, `d.save_resume(${a})`, `d.save_resume(${b})`,
    ]);
    expect(stub.content).toContain("<value><string>tv%20shows</string></value>");
    expect(stub.content).not.toContain("d.save_full_session");
    expect(stub.content).not.toContain("session.save");
  });

  it("saves a single relabelled download too", () => {
    const a = hashOf(3);
    const stub = new rTorrentStub(labelURI([a], ""));
    expect(members(stub.content)).toEqual([
      `${theRequestManager.map("d.set_custom1")}(${a})`, `d.save_resume(${a})`,
    ]);
  });

  it("still hands response handlers the relabelled downloads and nothing else", () => {
    // plugins/extratio rechecks this.hashes after a label is set: each
    // relabelled download once. toStrictEqual, because toEqual would skip
    // undefined entries.
    const hashes = [hashOf(4), hashOf(5), hashOf(6)];
    const stub = new rTorrentStub(labelURI(hashes, "films"));
    expect(stub.hashes).toStrictEqual(hashes);
  });
});

test("a selection that needs several requests sends every label and save once", () => {
  // rTorrent before API 11 takes 2 MB a request, so the stub splits a large
  // multicall; every part stays within the limit and hands its handler the
  // downloads its commands act on, a part of saves alone included.
  loadUI(null, 10);
  const hashes = Array.from({ length: 20000 }, (_, i) => hashOf(i + 1));
  const stub = new rTorrentStub(labelURI(hashes, "films"));
  const sent = [];
  let parts = 0;
  let savesAlone = false;
  for (;;) {
    const part = members(stub.content);
    expect(part.length).toBeGreaterThan(0);
    expect(stub.content.length).toBeLessThanOrEqual(2 << 20);
    const named = part.map((member) => member.slice(member.indexOf("(") + 1, -1));
    expect(stub.hashes).toStrictEqual(named.filter((hash, i) => named.indexOf(hash) === i));
    savesAlone = savesAlone || part.every((member) => member.startsWith("d.save_resume("));
    parts++;
    sent.push(...part);
    if (stub.commandOffset >= stub.commands.length)
      break;
    stub.makeNextMultiCall();
  }
  expect(parts).toBeGreaterThan(1);
  expect(savesAlone).toBe(true);
  const set = theRequestManager.map("d.set_custom1");
  expect(sent).toEqual([
    ...hashes.map((hash) => `${set}(${hash})`),
    ...hashes.map((hash) => `d.save_resume(${hash})`),
  ]);
});

test("with httprpc the server builds the saves from the same form", () => {
  loadUI(true);
  const [a, b] = [hashOf(7), hashOf(8)];
  const stub = new rTorrentStub(labelURI([a, b], "tv shows"));
  expect(stub.mountPoint).toBe("plugins/httprpc/action.php");
  expect(stub.commands).toHaveLength(0);
  expect(stub.content).toMatch(new RegExp(`^mode=setlabel&hash=${a}&hash=${b}&v=`));
  expect(stub.hashes).toStrictEqual([a, b]);
});
