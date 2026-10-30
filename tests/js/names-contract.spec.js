import { readFileSync } from "fs";

// The PHP suite writes these golden answers from the real
// plugins/httprpc/action.php on captured daemon replies and keeps them in sync.
// Each <step>.json is the exact body action.php printed for the POST body
// named in requests.json (tests/plugins/httprpc/NamesContractTest.php). Labels
// need each source's own info hash, so these are the captured torrents' hashes:
// the first two have cp1251 titles "Пример" and "Привет" (equal length, so both
// reach JSON as six U+FFFD), the third the UTF-8 title "Plain". The first one's
// files sit in cp1251 directories "Пример" and "Привет" and in UTF-8 "plain".
window.$ = require("jquery");
const fixtures = "js/fixtures/names-contract/";
const requests = JSON.parse(readFileSync(fixtures + "requests.json", "utf8"));
const [first, second, plain] = [
  "117C4C3C9EC4FA840B8C6B9E4DD35E4BC0FE45F3",
  "1AD8A6F063FF8DF6C928CA8DF939334D76F54662",
  "52512685729C47241B550B1E583DF5F854AEF34F",
];
const wire = "�".repeat(6);

function loadUI() {
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
  const script = document.createElement("script");
  script.textContent = "(function () { var plugin = { enabled: true };\n"
    + readFileSync("../plugins/httprpc/init.js", "utf8") + "\n})();";
  document.body.appendChild(script);
}

// The WebUI must send exactly what the server answered, then read that answer.
function exchange(query, step) {
  const stub = new rTorrentStub(query);
  expect(stub.content).toBe(requests[step]);
  const result = stub.getResponse(JSON.parse(readFileSync(`${fixtures}${step}.json`, "utf8")));
  expect(stub.faultString).toEqual([]);
  return { stub, result };
}

test("golden list answers label legacy titles and retire the labels with the names", () => {
  loadUI();
  let torrents = exchange("?list=1", "list-full").result.torrents;
  expect(Object.keys(torrents).sort()).toEqual([first, second, plain]);
  expect([first, second, plain].map(hash => torrents[hash].name)).toEqual(["Пример", "Привет", "Plain"]);
  expect([first, second, plain].map(hash => torrents[hash].path_name)).toEqual([wire, wire, "Plain"]);
  expect(theRequestManager.torrents[first][4]).toBe(wire);
  expect({ ...theRequestManager.torrentNames }).toEqual({ [first]: "Пример", [second]: "Привет" });

  torrents = exchange("?list=1", "list-delta").result.torrents;
  expect(torrents[first].dl).toBe(42);
  expect([first, second].map(hash => torrents[hash].name)).toEqual(["Пример", "Привет"]);

  torrents = exchange("?list=1", "list-rename").result.torrents;
  expect(torrents[first].name).toBe("Renamed");
  expect(torrents[first].path_name).toBe("Renamed");
  expect({ ...theRequestManager.torrentNames }).toEqual({ [second]: "Привет" });

  torrents = exchange("?list=1", "list-delete").result.torrents;
  expect(Object.keys(torrents).sort()).toEqual([first, plain]);
  expect({ ...theRequestManager.torrentNames }).toEqual({});
});

test("a golden file list keeps equal-length cp1251 directories apart by their byte keys", () => {
  loadUI();
  const { stub, result } = exchange(`?action=getfiles&hash=${first}`, "fls-names");
  const rows = JSON.parse(readFileSync(`${fixtures}fls-names.json`, "utf8"));
  expect(rows.map(row => row.length)).toEqual([stub.fileNameIndex + 1, stub.fileNameIndex + 1, stub.fileNameIndex]);
  expect(rows.map(row => row[0])).toEqual([`${wire}/a.mkv`, `${wire}/a.mkv`, "plain/b.mkv"]);
  const files = result[first];
  expect(files.map(file => file.name)).toEqual(["Пример/a.mkv", "Привет/a.mkv", "plain/b.mkv"]);
  expect(files.map(file => file.pathKey)).toEqual(["%CF%F0%E8%EC%E5%F0/a.mkv", "%CF%F0%E8%E2%E5%F2/a.mkv", undefined]);
  const directory = new rDirectory();
  files.forEach((file, index) => directory.addFile(file, index));
  const keys = ["_d_%CF%F0%E8%EC%E5%F0", "_d_%CF%F0%E8%E2%E5%F2", "_d_plain"];
  const entries = directory.getDirectory();
  expect(Object.keys(entries)).toEqual(keys);
  expect(keys.map(key => entries[key].data.name)).toEqual(["Пример", "Привет", "plain"]);
  keys.forEach((key, index) => {
    const ids = [];
    directory.getFilesIds(ids, "", key, 2, "priority");
    expect(ids).toEqual([String(index)]);
  });
});
