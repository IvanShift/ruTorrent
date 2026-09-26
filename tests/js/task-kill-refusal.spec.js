import { readFileSync } from "fs";

window.$ = require("jquery");

function loadTaskPlugin() {
  document.body.innerHTML = '<div id="tskConsole-header"></div><div id="tskcmderrors"></div>';
  window.plugin = { loadLang() {}, loadMainCSS() {}, canChangeTabs() { return false; } };
  window.theWebUI = { requestWithoutTimeout: jest.fn(), requestWithTimeout: jest.fn(), settings: { "webui.update_interval": 1000000 } };
  window.theDialogManager = { show: jest.fn(), clearModalState: jest.fn() };
  window.theUILang = { tskCommand: "Running...", tskKillRefused: "Cancellation refused", tskKillUnknown: "Cancellation unknown" };
  window.rTorrentStub = function () {};
  window.dxSTable = function () {};
  window.theTabs = { onShow() {} };
  window.noty = jest.fn();
  window.getCRC = () => 1;
  window.thePlugins = { get: () => null };
  window.escapeHTML = value => value;
  window.$type = value => value == null ? false : typeof value;
  const script = document.createElement("script");
  script.textContent = readFileSync("../plugins/_task/init.js", "utf8");
  document.body.appendChild(script);
  plugin.foreground = { no: "task-1", status: -1, options: {} };
  plugin.callNotification = jest.fn();
  window.realTaskCheck = plugin.check;
  plugin.check = jest.fn();
}

function killReply(index, result) {
  const callback = theWebUI.requestWithTimeout.mock.calls[index][1];
  expect(callback).toEqual([expect.any(Function), plugin]);
  callback[0].call(callback[1], result);
}

function checkReply(index, result) {
  const callback = theWebUI.requestWithoutTimeout.mock.calls[index][1];
  expect(callback).toEqual([expect.any(Function), plugin]);
  callback[0].call(callback[1], result);
}

beforeEach(loadTaskPlugin);

test("a refused cancellation stays active and displays the task diagnostics", () => {
  plugin.kill();
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Finished");
  killReply(0, false);
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Finished");
  expect(plugin.foreground.no).toBe("task-1");
  expect(noty).toHaveBeenCalledWith("Cancellation refused", "error");
  expect(theWebUI.requestWithoutTimeout.mock.calls[0][0]).toBe("?action=taskcheck&hash=task-1");
  const state = { no: "task-1", status: -1, errors: ["pid identity mismatch"] };
  checkReply(0, state);
  expect(plugin.check).toHaveBeenCalledWith(state);
  expect(theDialogManager.show).toHaveBeenCalledWith("tskConsole");
});

test("shutdown waits for confirmed cancellation before sending completion events", () => {
  plugin.shutdown();
  expect(plugin.callNotification).not.toHaveBeenCalled();
  expect(plugin.foreground.no).toBe("task-1");
  killReply(0, true);
  expect(plugin.callNotification.mock.calls.map(([event]) => event)).toEqual([
    "Finished", "HideInterface", "Shutdown",
  ]);
  expect(plugin.foreground.no).toBe(0);
});

test("shutdown refusal keeps the task and does not send completion events", () => {
  plugin.shutdown();
  killReply(0, false);
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Finished");
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Shutdown");
  expect(plugin.foreground.no).toBe("task-1");
  expect(theWebUI.requestWithoutTimeout.mock.calls[0][0]).toBe("?action=taskcheck&hash=task-1");
});


test("automatic removal of a completed task waits for server confirmation", () => {
  plugin.foreground.no = 0;
  plugin.onStart({ no: "task-1", status: 0 });
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Shutdown");
  expect(plugin.foreground.no).toBe("task-1");
  killReply(0, true);
  expect(plugin.callNotification).toHaveBeenCalledWith("Shutdown");
  expect(plugin.foreground.no).toBe(0);
});

test("an old cancellation response cannot finish a different foreground task", () => {
  plugin.kill();
  plugin.foreground.no = "task-2";
  killReply(0, true);
  expect(plugin.callNotification).not.toHaveBeenCalled();
  expect(plugin.foreground.no).toBe("task-2");
});


test("taskcheck displays the server reason after a refused cancellation", () => {
  plugin.check = realTaskCheck;
  plugin.kill();
  killReply(0, false);
  checkReply(0, { no: "task-1", pid: 123, status: -1, params: {},
    log: [], errors: ["rtask: TaskKill failed (pid identity mismatch)"] });
  expect($("#tskcmderrors").text()).toContain("pid identity mismatch");
  plugin.clearForeTimeout();
});


test("an untranslated locale sees the English cancellation refusal", () => {
  delete theUILang.tskKillRefused;
  plugin.kill();
  killReply(0, false);
  expect(noty).toHaveBeenCalledWith(
    "Cancellation was refused; see task diagnostics.", "error");
});


test("a regular completion reply waits while cancellation is unresolved", () => {
  plugin.check = realTaskCheck;
  plugin.foreground.options.noclose = true;
  plugin.kill();
  plugin.check({ no: "task-1", pid: 123, status: 0, params: {}, log: [], errors: [] });
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Finished");
});

test("a stale diagnostic reply cannot reopen after a second cancellation attempt", () => {
  plugin.kill();
  killReply(0, false);
  plugin.kill();
  checkReply(0, { no: "task-1", status: -1, errors: ["old refusal"] });
  expect(plugin.check).not.toHaveBeenCalled();
  expect(theDialogManager.show).not.toHaveBeenCalled();
});


test("a transport failure leaves cancellation unconfirmed and visible", () => {
  plugin.shutdown();
  const timeout = theWebUI.requestWithTimeout.mock.calls[0][2];
  timeout();
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Finished");
  expect(plugin.callNotification).not.toHaveBeenCalledWith("Shutdown");
  expect(noty).toHaveBeenCalledWith("Cancellation unknown", "error");
  expect(theWebUI.requestWithoutTimeout.mock.calls[0][0]).toBe("?action=taskcheck&hash=task-1");
});
