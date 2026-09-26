import { readFileSync } from "fs";

window.$ = require("jquery");

function load(src, prefix = "", suffix = "") {
  const script = document.createElement("script");
  script.textContent = prefix + readFileSync(src, "utf8") + suffix;
  document.body.appendChild(script);
}

test("erasedata keeps its force value after httprpc initializes", () => {
  window.theWebUI = { settings: {}, perform: () => {} };
  window.theUILang = {};
  for (const src of ["../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(src);
  load("../plugins/erasedata/init.js",
    "(function () { var plugin = { enabled: true, replaceRemoveTorrent: true, " +
      "force_delete: false, enableForceDeletion: false, loadLang() {}, canChangeMenu() { return true; } };\n",
    "\n})();");
  load("../plugins/httprpc/init.js",
    "(function () { var plugin = { enabled: true };\n", "\n})();");

  window.noty = jest.fn();
  const hash = "A".repeat(40);
  const request = new rTorrentStub("?action=removewithdata&hash=" + hash);
  expect(request.mountPoint).toBe("plugins/httprpc/action.php");
  expect(request.content).toBe("mode=removewithdata&hash=" + hash + "&v=1");
  const partial = { refused: ["invalid-hash:string"], retained: [] };
  expect(request.getResponse(partial)).toBe(partial);
  expect(noty).toHaveBeenCalledWith(
    "Deletion partly refused: 1 torrent(s). Check the server log.", "error");
});
