import { readFileSync } from "fs";

window.$ = require("jquery");

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
  if (enabled !== null) {
    const script = document.createElement("script");
    script.textContent = `(function () { var plugin = { enabled: ${enabled} };\n`
      + readFileSync("../plugins/httprpc/init.js", "utf8") + "\n})();";
    document.body.appendChild(script);
  }
}

const hash = "A".repeat(40);
const row = (name, size = 100) => [name, "1", "2", String(size), "1"];

// The label cell follows every requested column, so pad a row up to it.
function labelled(request, values, label) {
  const cells = values.slice();
  while (cells.length < request.fileNameIndex) cells.push("0");
  cells.push(label);
  return cells;
}

const legacy = "�".repeat(5) + ".mkv";
const series = { displayName: "Серия.mkv", pathKey: "%D1%E5%F0%E8%FF.mkv" };

test.each([null, false, true])("file names ask the shared server resolver for label cells with httprpc %s", enabled => {
  loadUI(enabled);
  theRequestManager.addRequest("fls", "f.get_priority=", () => {});
  const request = new rTorrentStub(`?action=getfiles&hash=${hash}`);
  expect(request.mountPoint).toBe("plugins/httprpc/action.php");
  expect(request.dataType).toBe("json");
  expect(request.content).toBe(`mode=fls&hash=${hash}&cmd=f.prioritize_first%3D&cmd=f.prioritize_last%3D&cmd=f.priority%3D&names=1`);
  expect(request.fileNameIndex).toBe(theRequestManager.fls.commands.length);
  expect(request.fileNameIndex).toBe(8);
  expect(request.commands).toHaveLength(0);
});

test("a file name that is not a string, such as an older fork server's envelope, refuses the answer visibly", () => {
  loadUI(true);
  const request = new rTorrentStub(`?action=getfiles&hash=${hash}`);
  request.getResponse([row("plain.mkv"), row({ name: "Серия.mkv", pathKey: series.pathKey })]);
  expect(request.faultString).toEqual(["Invalid file list response [getfiles]"]);
});

test("a label cell reaches the model with its byte key while extension columns stay in place", () => {
  loadUI(true);
  let extensionResult;
  theRequestManager.addRequest("fls", "f.get_priority=", (hash, _files, value) => { extensionResult = { hash, value }; });
  const request = new rTorrentStub(`?action=getfiles&hash=${hash}`);
  const wire = "Panty And Stocking TV 01 " + legacy;
  const label = {
    displayName: "Panty And Stocking TV 01 серия.mkv",
    pathKey: "Panty%20And%20Stocking%20TV%2001%20%F1%E5%F0%E8%FF.mkv",
  };
  const input = row(wire, 3014966836);
  input.push("0", "0", "2", label);
  expect(input.indexOf(label)).toBe(request.fileNameIndex);
  const file = request.getResponse([input])[hash][0];
  expect(request.isError()).toBe(0);
  expect(file.name).toBe(label.displayName);
  expect(file.pathKey).toBe(label.pathKey);
  expect(file.size).toBe(3014966836);
  expect(file.done).toBe(1507483418);
  expect(extensionResult).toEqual({ hash, value: "2" });
  expect(input[0]).toBe(wire);
});

test("valid Unicode and file indices survive alongside legacy names", () => {
  loadUI(true);
  const request = new rTorrentStub(`?action=getfiles&hash=${hash}`);
  const files = request.getResponse([
    row("映画/Фильм 🎬.mkv"),
    labelled(request, row(legacy), series),
    row(" literal %F1!().mkv "),
  ])[hash];
  expect(files.map(file => file.name)).toEqual([
    "映画/Фильм 🎬.mkv", "Серия.mkv", " literal %F1!().mkv ",
  ]);
  expect(files.map(file => file.pathKey)).toEqual([undefined, series.pathKey, undefined]);
  const directory = new rDirectory();
  files.forEach((file, index) => directory.addFile(file, index));
  const ids = [];
  directory.getFilesIds(ids, "", "_f_1", 0);
  expect(ids).toEqual(["1"]);
});

test("equal-length legacy directories with one wire spelling stay separate through their byte keys", () => {
  loadUI(true);
  const request = new rTorrentStub(`?action=getfiles&hash=${hash}`);
  // cp1251 "Пример" and "Привет": JSON substitutes both to six U+FFFD.
  const wire = "�".repeat(6) + "/a.mkv";
  const files = request.getResponse([
    labelled(request, row(wire), { displayName: "Пример/a.mkv", pathKey: "%CF%F0%E8%EC%E5%F0/a.mkv" }),
    labelled(request, row(wire), { displayName: "Привет/a.mkv", pathKey: "%CF%F0%E8%E2%E5%F2/a.mkv" }),
  ])[hash];
  const directory = new rDirectory();
  files.forEach((file, index) => directory.addFile(file, index));
  const entries = directory.getDirectory();
  const keys = ["_d_%CF%F0%E8%EC%E5%F0", "_d_%CF%F0%E8%E2%E5%F2"];
  expect(Object.keys(entries)).toEqual(keys);
  expect(keys.map(key => entries[key].data.name)).toEqual(["Пример", "Привет"]);
  keys.forEach((key, index) => {
    const ids = [];
    directory.getFilesIds(ids, "", key, 2, "priority");
    expect(ids).toEqual([String(index)]);
  });
});

test.each([
  ["a string", "Серия.mkv"],
  ["an array", ["Серия.mkv", series.pathKey]],
  ["null", null],
  ["no display name", { pathKey: series.pathKey }],
  ["a numeric display name", { displayName: 5, pathKey: series.pathKey }],
  ["an extra display component", { displayName: "a/Серия.mkv", pathKey: series.pathKey }],
  ["an empty display name", { displayName: "", pathKey: series.pathKey }],
  ["a dot display name", { displayName: ".", pathKey: series.pathKey }],
  ["a dot-dot display name", { displayName: "..", pathKey: series.pathKey }],
  ["a NUL in the display name", { displayName: "Се\0рия.mkv", pathKey: series.pathKey }],
])("an invalid label cell (%s) leaves the daemon's string and no byte key", (_name, label) => {
  loadUI(true);
  const request = new rTorrentStub(`?action=getfiles&hash=${hash}`);
  const files = request.getResponse([labelled(request, row(legacy), label), row("plain.mkv")])[hash];
  expect(request.isError()).toBe(0);
  expect(files.map(file => file.name)).toEqual([legacy, "plain.mkv"]);
  expect(files[0].pathKey).toBeUndefined();
});

test.each([
  ["no byte key", undefined],
  ["a non-string byte key", [series.pathKey]],
  ["an extra key component", "a/" + series.pathKey],
  ["an empty byte key", ""],
  ["a lowercase escape", "%d1%E5%F0%E8%FF.mkv"],
  ["a query delimiter", "x&names=1"],
])("a label whose byte key is unusable (%s) keys the row and its extension by the wire string", (_name, pathKey) => {
  loadUI(true);
  const request = new rTorrentStub(`?action=getfiles&hash=${hash}`);
  const label = { displayName: "Серия.rar", ...(pathKey === undefined ? {} : { pathKey }) };
  const file = request.getResponse([labelled(request, row(legacy), label)])[hash][0];
  expect(request.isError()).toBe(0);
  expect(file.name).toBe("Серия.rar");
  expect(file.pathKey).toBe("%EF%BF%BD".repeat(5) + ".mkv");
  expect(fileExtension(file)).toBe("mkv");
});

test("equal display labels cannot merge separate raw directories or their selected files", () => {
  loadUI();
  const directory = new rDirectory();
  directory.addFile({ ...rowData(), name: "Сезон/one.mkv", pathKey: "%D1%E5%E7%EE%ED/one.mkv" }, 83);
  directory.addFile({ ...rowData(), name: "Сезон/two.mkv" }, 84);
  const raw = "_d_%D1%E5%E7%EE%ED";
  const utf8 = "_d_%D0%A1%D0%B5%D0%B7%D0%BE%D0%BD";
  const entries = directory.getDirectory();
  expect(Object.keys(entries)).toEqual([raw, utf8]);
  expect(entries[raw].data.name).toBe("Сезон");
  expect(entries[utf8].data.name).toBe("Сезон");
  const ids = [];
  directory.getFilesIds(ids, "", raw, 2, "priority");
  expect(ids).toEqual(["83"]);
  directory.setDirectory(entries[raw].link);
  expect(directory.getEntry("_f_83").name).toBe("one.mkv");
  expect(directory.getEntry("_f_84")).toBeNull();
  expect(directory.getDirectory()["_d_"].link).toBe("");
});

test("valid prefixes share one directory with legacy children, including percent and punctuation", () => {
  loadUI();
  const directory = new rDirectory();
  directory.addFile({ ...rowData(), name: "a %!()'/серия.mkv", pathKey: "a%20%25%21%28%29%27/%F1%E5%F0%E8%FF.mkv" }, 1);
  directory.addFile({ ...rowData(), name: "a %!()'/plain.mkv" }, 2);
  const entries = directory.getDirectory();
  expect(Object.keys(entries)).toEqual(["_d_a%20%25%21%28%29%27"]);
  expect(entries["_d_a%20%25%21%28%29%27"].data.name).toBe("a %!()'");
  const ids = [];
  directory.getFilesIds(ids, "", Object.keys(entries)[0], 0);
  expect(ids).toEqual(["1", "2"]);
});

test("directory names that match Object properties remain selectable", () => {
  loadUI();
  const directory = new rDirectory();
  directory.addFile({ ...rowData(), name: "__proto__/constructor/file.mkv" }, 4);
  const ids = [];
  directory.getFilesIds(ids, "", "_d___proto__", 0);
  expect(ids).toEqual(["4"]);
  const polluted = Object.prototype.hasOwnProperty.call(Array.prototype, "_d___proto__/constructor");
  delete Array.prototype["_d___proto__/constructor"];
  expect(polluted).toBe(false);
});

test("legacy leaf labels and recovering directory labels update without changing selection", () => {
  loadUI();
  const directory = new rDirectory();
  const pathKey = "%D1%E5%E7%EE%ED/%CF%E0%EF%EA%E0/%F1%E5%F0%E8%FF.mkv";
  directory.addFile({ ...rowData(), name: "old/old/old.mkv", pathKey }, 83);
  directory.addFile({ ...rowData(), name: "Сезон/Папка/серия.mkv", pathKey }, 83);
  const outer = directory.getDirectory()["_d_%D1%E5%E7%EE%ED"];
  expect(outer.data.name).toBe("Сезон");
  directory.setDirectory(outer.link);
  const inner = directory.getDirectory()["_d_%D1%E5%E7%EE%ED/%CF%E0%EF%EA%E0"];
  expect(inner.data.name).toBe("Папка");
  directory.setDirectory(inner.link);
  expect(directory.getEntry("_f_83").name).toBe("серия.mkv");
  const ids = [];
  directory.getFilesIds(ids, directory.current, "_f_83", 0);
  expect(ids).toEqual(["83"]);
});

function rowData() {
  return { size: 100, done: 50, priority: "1", prioritize: 0 };
}
