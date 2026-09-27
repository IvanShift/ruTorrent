import { readFileSync } from "fs";

window.$ = require("jquery");

function loadCookieSettings() {
  document.body.innerHTML = '<textarea id="hostcookies"></textarea>';
  window.hostCookies = [
    { host: "one.test", cookies: "********" },
    { host: "two.test", cookies: "********" },
  ];
  window.plugin = {
    enabled: true,
    loadMainCSS() {},
    loadLang() {},
    canChangeOptions() { return true; },
  };
  window.theWebUI = {
    addAndShowSettings: jest.fn(),
    setSettings: jest.fn(),
    request: jest.fn(),
    requestWithTimeout: jest.fn(),
    error: jest.fn(),
    timeout: jest.fn(),
  };
  window.rTorrentStub = function () {};
  const script = document.createElement("script");
  script.textContent = readFileSync("../plugins/cookies/init.js", "utf8");
  document.body.appendChild(script);
}

test("masked cookies stay unchanged until a real edit and are submitted for server-side preservation", () => {
  loadCookieSettings();
  theWebUI.addAndShowSettings();
  expect($("#hostcookies").val()).toBe("one.test|********\ntwo.test|********");
  expect(theWebUI.cookiesWasChanged()).toBe(false);
  theWebUI.setSettings();
  expect(theWebUI.request).not.toHaveBeenCalled();

  $("#hostcookies").val("one.test|********\ntwo.test|********\nfresh.test|sid=new");
  expect(theWebUI.cookiesWasChanged()).toBe(true);
  theWebUI.setSettings();
  expect(theWebUI.requestWithTimeout).toHaveBeenCalledWith("?action=setcookies", null, theWebUI.timeout, expect.any(Function));
  const stub = new rTorrentStub();
  stub.setcookies();
  expect(stub.mountPoint).toBe("plugins/cookies/action.php");
  expect(new URLSearchParams(stub.content).getAll("cookie")).toEqual([
    "one.test|********", "two.test|********", "fresh.test|sid=new",
  ]);
});

test("a rejected masked host rename shows an actionable cookie settings error", () => {
  loadCookieSettings();
  theWebUI.addAndShowSettings();
  $("#hostcookies").val("renamed.test|********\ntwo.test|********");
  theWebUI.setSettings();
  expect(theWebUI.requestWithTimeout).toHaveBeenCalledTimes(1);
  const onError = theWebUI.requestWithTimeout.mock.calls[0][3];
  onError("400 [error,setcookies]", "Bad Request");
  expect(theWebUI.error).toHaveBeenCalledWith(
    "400 [error,setcookies]", expect.stringContaining("full cookie string"),
  );
});
