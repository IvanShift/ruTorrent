import { readFileSync } from "fs";

window.$ = require("jquery");

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
  };
  for (const source of ["../lang/en.js", "../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(source);
  correctContent();
  if (httprpc !== null)
    load("../plugins/httprpc/init.js",
      `(function () { var plugin = { enabled: ${httprpc} };\n`, "\n})();");
}

describe.each([
  ["without httprpc init", null],
  ["with httprpc disabled", false],
  ["with httprpc enabled", true],
])("trusted mutation route %s", (_name, httprpc) => {
  beforeEach(() => loadUI(httprpc));

  it.each(["recheck", "remove"])("routes %s through its trusted server mode", (action) => {
    const hashes = ["A".repeat(40), "B".repeat(40)];
    const stub = new rTorrentStub(
      `?action=${action}&hash=${hashes[0]}&hash=${hashes[1]}`
    );
    expect(stub.mountPoint).toBe("plugins/httprpc/action.php");
    expect(stub.contentType).toBe("application/x-www-form-urlencoded");
    expect(stub.dataType).toBe("json");
    expect(stub.content).toBe(`mode=${action}&hash=${hashes[0]}&hash=${hashes[1]}`);
    expect(stub.commands).toHaveLength(0);
  });

  it("keeps an ordinary property write on the same server route", () => {
    const hash = "A".repeat(40);
    const stub = new rTorrentStub(`?action=setprops&hash=${hash}&s=peers_min&v=5`);
    expect(stub.content).toBe(`mode=setprops&hash=${hash}&v=5&s=peers_min`);
    expect(stub.commands).toHaveLength(0);
  });

  it("uses the validated server helper for superseed and ordinary properties", () => {
    const hash = "A".repeat(40);
    const stub = new rTorrentStub(
      `?action=setprops&hash=${hash}&s=peers_max&v=20&s=superseed&v=1`
    );
    expect(stub.mountPoint).toBe("plugins/httprpc/action.php");
    expect(stub.contentType).toBe("application/x-www-form-urlencoded");
    expect(stub.dataType).toBe("json");
    expect(stub.content).toBe(
      `mode=setprops&hash=${hash}&v=20&v=1&s=peers_max&s=superseed`
    );
    expect(stub.commands).toHaveLength(0);
  });
});

test("a missing server helper reports its HTTP 404 to the caller", () => {
  loadUI(null);
  const deferred = $.Deferred();
  const ajax = jest.spyOn($, "ajax").mockReturnValue(deferred.promise());
  const onError = jest.fn();
  Ajax(new rTorrentStub(`?action=setprops&hash=${"A".repeat(40)}&s=superseed&v=1`),
    null, null, null, onError, 10000);
  deferred.reject({
    status: 404,
    responseText: "Not Found",
    getResponseHeader: () => null,
  }, "error", "Not Found");
  expect(onError).toHaveBeenCalledWith("404 [error,setprops]", "Not Found");
  ajax.mockRestore();
});
