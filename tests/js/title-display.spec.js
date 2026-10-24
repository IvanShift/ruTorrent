import { readFileSync } from "fs";

window.$ = require("jquery");
const hash = "A".repeat(40);

function loadUI(enabled = null) {
  window.theWebUI = {
    settings: { "webui.needmessage": true },
    showFlags: 0xffff,
    systemInfo: { rTorrent: { apiVersion: 26, iVersion: 0x101a, started: true } },
  };
  for (const file of ["lang/en.js", "js/common.js", "js/content.js", "js/rtorrent.js"]) {
    const script = document.createElement("script");
    script.textContent = readFileSync(`../${file}`, "utf8");
    document.body.appendChild(script);
  }
  correctContent();
  for (const [path, plugin] of [
    ...(enabled === null ? [] : [["httprpc", `{ enabled: ${enabled} }`]]),
    ["datadir", "{ loadMainCSS() {}, loadLang() {}, canChangeMenu() { return false; } }"],
  ]) {
    const script = document.createElement("script");
    script.textContent = `(function () { var plugin = ${plugin};\n`
      + readFileSync(`../plugins/${path}/init.js`, "utf8") + "\n})();";
    document.body.appendChild(script);
  }
}

function row(name, path) {
  const values = Array(36).fill("0");
  values[4] = name; values[7] = "2"; values[25] = path; values[29] = "";
  values[35] = "plugin-value";
  return values;
}

test.each([null, false, true])("title list uses the server resolver with httprpc %s", enabled => {
  loadUI(enabled);
  theRequestManager.cid = 123;
  theRequestManager.addRequest("trt", "d.get_custom3=", () => {});
  const stub = new rTorrentStub("?list=1");
  expect(stub.mountPoint).toBe("plugins/httprpc/action.php");
  expect(stub.dataType).toBe("json");
  expect(stub.content).toBe("mode=list&cid=123&cmd=d.custom3%3D");
  expect(stub.commands).toHaveLength(0);
});

test.each([null, false, true])("core no-message preference survives the JSON list route with httprpc %s", enabled => {
  loadUI(enabled);
  theWebUI.settings["webui.needmessage"] = false;
  expect(new rTorrentStub("?list=1").content).toBe(enabled === true ? "mode=list" : "mode=list&nomsg=1");
});

test("display aliases preserve the old parent directory and extension indices", () => {
  loadUI();
  const wireName = "�����";
  let extension;
  theRequestManager.addRequest("trt", "d.get_custom3=", (id, torrent, value) => { extension = { id, name: torrent.name, value }; });
  const stub = new rTorrentStub("?list=1");
  const values = row({ name: "Серия", raw: wireName }, `/downloads/${wireName}`);
  const model = stub.getResponse({ t: { [hash]: values }, cid: 1, full: 1 }).torrents[hash];
  expect(model.name).toBe("Серия");
  expect(model.path_name).toBe(wireName);
  expect(model.base_path).toBe(`/downloads/${wireName}`);
  expect(model.save_path).toBe("/downloads");
  expect(extension).toEqual({ id: hash, name: "Серия", value: "plugin-value" });
  expect(values[4]).toEqual({ name: "Серия", raw: wireName });
  theWebUI.torrents = { [hash]: model };
  const pathRequest = new rTorrentStub(`?action=getsavepath&hash=${hash}`);
  expect(pathRequest.getResponse(["0", `/downloads/${wireName}`, "0"])).toEqual({ hash, savepath: "/downloads" });
  const removal = new rTorrentStub(`?action=remove&hash=${hash}`);
  expect(removal.content).toBe(`mode=remove&hash=${hash}`);
});

test("sparse updates keep the display envelope and replace it atomically on a name change", () => {
  loadUI();
  const stub = new rTorrentStub("?list=1");
  stub.getResponse({ t: { [hash]: row({ name: "Display", raw: "�" }, "/downloads/�") }, cid: 1, full: 1 });
  let model = stub.getResponse({ t: { [hash]: { 11: "1234" } }, cid: 2 }).torrents[hash];
  expect(model.name).toBe("Display");
  expect(model.ul).toBe(1234);
  expect(model.save_path).toBe("/downloads");
  model = stub.getResponse({ t: { [hash]: { 4: { name: "Next display", raw: "��" }, 25: "/downloads/��" } }, cid: 3 }).torrents[hash];
  expect(model.name).toBe("Next display");
  expect(model.path_name).toBe("��");
  expect(model.save_path).toBe("/downloads");
  model = stub.getResponse({ t: { [hash]: { 4: "映画 🙂", 25: "/downloads/映画 🙂" } }, cid: 4 }).torrents[hash];
  expect(model.name).toBe("映画 🙂");
  expect(model.path_name).toBe("映画 🙂");
  expect(model.save_path).toBe("/downloads");
});

test("a directory named like the display alias is not confused with the raw title", () => {
  loadUI();
  const model = new rTorrentStub("?list=1").getResponse({ t: { [hash]: row({ name: "Display", raw: "�" }, "/downloads/Display") }, cid: 1, full: 1 }).torrents[hash];
  expect(model.save_path).toBe("/downloads/Display");
  theWebUI.torrents = { [hash]: model };
  expect(new rTorrentStub(`?action=getsavepath&hash=${hash}`).getResponse(["0", "/downloads/Display", "0"]).savepath).toBe("/downloads/Display");
});

const legacyPaths = {
  base: { name: "/\\xFF/\\xFE", pathKey: "%2F%FF%2F%FE" },
  save: { name: "/\\xFF", pathKey: "%2F%FF" },
};

test("raw save paths survive sparse updates, path changes and the datadir response", () => {
  loadUI();
  const stub = new rTorrentStub("?list=1");
  let model = stub.getResponse({ t: { [hash]: row("�", "/�/�") }, p: { [hash]: legacyPaths }, cid: 1, full: 1 }).torrents[hash];
  expect(model.base_path).toBe(legacyPaths.base.name);
  expect(model.save_path_value).toEqual(legacyPaths.save);
  model = stub.getResponse({ t: { [hash]: { 11: "5" } }, cid: 2 }).torrents[hash];
  expect(model.save_path_value).toEqual(legacyPaths.save);
  theWebUI.torrents = { [hash]: model };
  const request = new rTorrentStub(`?action=getsavepath&hash=${hash}`);
  expect(request.getResponse(["0", "/�/�", "0", legacyPaths]).savepath).toEqual(legacyPaths.save);
  const changed = { base: legacyPaths.base, save: legacyPaths.base };
  model = stub.getResponse({ t: { [hash]: { 4: "different" } }, p: { [hash]: changed }, cid: 3 }).torrents[hash];
  expect(model.save_path_value).toEqual(legacyPaths.base);
  model = stub.getResponse({ t: { [hash]: { 25: "/new/different" } }, cid: 4 }).torrents[hash];
  expect(model.save_path_value).toBeUndefined();
  expect(model.save_path).toBe("/new");
});

test.each(["full", "delete"])("%s removes the remembered raw path before hash reuse", reset => {
  loadUI();
  const stub = new rTorrentStub("?list=1");
  stub.getResponse({ t: { [hash]: row("�", "/�/�") }, p: { [hash]: legacyPaths }, cid: 1, full: 1 });
  stub.getResponse({ t: {}, ...(reset === "full" ? { full: 1 } : { d: [hash] }), cid: 2 });
  expect(Object.keys(theRequestManager.torrentPaths)).toHaveLength(0);
  expect(stub.getResponse({ t: { [hash]: row("new", "/plain/new") }, cid: 3 }).torrents[hash].save_path).toBe("/plain");
});

test.each([
  null, [], { invalid: legacyPaths }, { [hash]: null },
  { [hash]: { base: legacyPaths.base, save: { name: "bad", pathKey: "%FF%2f" } } },
  { [hash]: { base: legacyPaths.base, save: { name: "bad", pathKey: "x&dir=bad" } } },
])("malformed path metadata is rejected before cache mutation", p => {
  loadUI();
  const stub = new rTorrentStub("?list=1");
  stub.getResponse({ t: { [hash]: row("�", "/�/�") }, p: { [hash]: legacyPaths }, cid: 1, full: 1 });
  const before = JSON.stringify(theRequestManager.torrents);
  stub.getResponse({ t: { [hash]: row("new", "/new") }, p, d: [hash], full: 1, cid: 2 });
  expect(stub.isError()).toBeTruthy();
  expect(theRequestManager.cid).toBe(1);
  expect(JSON.stringify(theRequestManager.torrents)).toBe(before);
  expect(theRequestManager.torrentPaths[hash]).toEqual(legacyPaths);
});

test("a path projection must accompany its own name or base-path update", () => {
  loadUI();
  const stub = new rTorrentStub("?list=1");
  stub.getResponse({ t: { [hash]: row("raw", "/raw") }, cid: 1, full: 1 });
  stub.getResponse({ t: { [hash]: { 11: "1" } }, p: { [hash]: legacyPaths }, cid: 2 });
  expect(stub.isError()).toBeTruthy();
  expect(theRequestManager.cid).toBe(1);
});

test("rejected path metadata reaches the caller error once without a second notice", () => {
  loadUI();
  window.noty = jest.fn();
  const complete = jest.fn(), error = jest.fn();
  const previous = $.ajax;
  const request = $.Deferred();
  $.ajax = () => request;
  try {
    Ajax("?list=1", true, complete, jest.fn(), error);
    request.resolve({ t: {}, p: null, cid: 99 }, "success", { status: 200, getResponseHeader: () => null });
    expect(error).toHaveBeenCalledTimes(1);
    expect(error.mock.calls[0][0]).toBe("200 [parsererror,list]");
    expect(complete).not.toHaveBeenCalled();
    expect(noty).not.toHaveBeenCalled();
    expect(theRequestManager.cid).toBe(0);
  } finally { $.ajax = previous; }
});
