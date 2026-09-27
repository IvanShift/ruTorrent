import { readFileSync } from "fs";

window.$ = require("jquery");

function load(src, prefix = "", suffix = "") {
  const script = document.createElement("script");
  script.textContent = prefix + readFileSync(src, "utf8") + suffix;
  document.body.appendChild(script);
}

const hash = "A".repeat(40);

beforeEach(() => {
  window.theUILang = {};
  window.theWebUI = {
    settings: {},
    systemInfo: { rTorrent: { apiVersion: 24, iVersion: 0x1018, started: true } },
    torrents: { [hash]: { name: "file.mkv" } },
  };
  for (const src of ["../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(src);
  load("../plugins/datadir/init.js",
    "(function () { var plugin = { loadMainCSS() {}, loadLang() {}, canChangeMenu() { return false; } };\n",
    "\n})();");
});

test("a closed torrent's data directory uses the existing server mode without httprpc", () => {
  const stub = new rTorrentStub(`?action=getsavepath&hash=${hash}`);
  expect(stub.mountPoint).toBe("plugins/httprpc/action.php");
  expect(stub.dataType).toBe("json");
  expect(stub.commands).toHaveLength(0);
  expect(new URLSearchParams(stub.content).get("mode")).toBe("getsavepath");
  expect(new URLSearchParams(stub.content).getAll("hash")).toStrictEqual([hash]);
  expect(stub.getResponse([0, "/data/file.mkv", 0])).toStrictEqual({
    hash, savepath: "/data",
  });
});

test("a loaded but disabled httprpc plugin keeps the checked server route", () => {
  load("../plugins/httprpc/init.js",
    "(function () { var plugin = { enabled: false };\n", "\n})();");
  const stub = new rTorrentStub(`?action=getsavepath&hash=${hash}`);
  expect(stub.mountPoint).toBe("plugins/httprpc/action.php");
  expect(stub.dataType).toBe("json");
  expect(stub.commands).toHaveLength(0);
  expect(new URLSearchParams(stub.content).getAll("hash")).toStrictEqual([hash]);
});
