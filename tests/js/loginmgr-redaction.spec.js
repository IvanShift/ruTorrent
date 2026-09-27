import { readFileSync } from "fs";

window.$ = require("jquery");

function settings(enabled = 1) {
  document.body.innerHTML = "";
  window.theWebUI = {
    theAccounts: {
      RUTracker: { enabled, configurationRequired: false, login: "saved-user", password: "", auto: 0 },
    },
    addAndShowSettings() {},
    setSettings() {},
  };
  window.theUILang = {
    Enabled: "Enabled", accAuto: "Auto", accLogin: "Login", accPassword: "Password",
    accClearPassword: "Clear saved password", accAccounts: "Accounts",
    acAutoNone: "None", acAutoDay: "Day", acAutoWeek: "Week", acAutoMonth: "Month",
  };
  window.linked = jest.fn();
  window.rTorrentStub = function () {};
  let page;
  window.plugin = {
    enabled: true,
    loadLang() {},
    canChangeOptions() { return true; },
    attachPageToOptions(element) { page = element; },
  };
  const script = document.createElement("script");
  script.textContent = readFileSync("../plugins/loginmgr/init.js", "utf8");
  document.body.appendChild(script);
  window.plugin.onLangLoaded();
  document.body.appendChild(page);
  window.theWebUI.addAndShowSettings();
  return page;
}

test("editing login leaves a redacted password blank and clear is explicit", () => {
  const page = settings();
  const password = $(page).find("#RUTracker_lmpassword");
  expect(password.val()).toBe("");
  expect(window.plugin.accWasChanged()).toBe(false);

  $(page).find("#RUTracker_lmlogin").val("edited-user");
  const edit = new window.rTorrentStub();
  edit.setacc();
  expect(new URLSearchParams(edit.content).get("RUTracker_password")).toBe("");
  expect(new URLSearchParams(edit.content).has("RUTracker_clear_password")).toBe(false);

  const clear = $(page).find("#RUTracker_lmclear_password");
  expect(clear.length).toBe(1);
  clear.prop("checked", true).trigger("change");
  expect(password.prop("disabled")).toBe(true);
  const cleared = new window.rTorrentStub();
  cleared.setacc();
  expect(new URLSearchParams(cleared.content).get("RUTracker_clear_password")).toBe("1");
});

test("successful account save removes typed and clear values from the form", () => {
  const page = settings();
  const password = $(page).find("#RUTracker_lmpassword");
  const clear = $(page).find("#RUTracker_lmclear_password");
  window.theWebUI.request = jest.fn();
  password.val("typed-new-password");
  window.theWebUI.setSettings();
  const reply = window.theWebUI.request.mock.calls[0][1];
  expect(password.val()).toBe("typed-new-password");
  expect(Array.isArray(reply)).toBe(true);
  reply[0].apply(reply[1], [null, reply[2]]);
  expect(password.val()).toBe("");
  expect(window.plugin.accWasChanged()).toBe(false);

  window.theWebUI.request.mockClear();
  clear.prop("checked", true).trigger("change");
  window.theWebUI.setSettings();
  const clearReply = window.theWebUI.request.mock.calls[0][1];
  expect(clear.prop("checked")).toBe(true);
  clearReply[0].apply(clearReply[1], [null, clearReply[2]]);
  expect(clear.prop("checked")).toBe(false);
  expect(password.prop("disabled")).toBe(false);
  expect(window.plugin.accWasChanged()).toBe(false);
});


test("a later password edit survives an earlier save response", () => {
  const page = settings();
  const password = $(page).find("#RUTracker_lmpassword");
  window.theWebUI.request = jest.fn();
  password.val("first-new-password");
  window.theWebUI.setSettings();
  const reply = window.theWebUI.request.mock.calls[0][1];
  password.val("later-new-password");
  reply[0].apply(reply[1], [null, reply[2]]);
  expect(password.val()).toBe("later-new-password");
});


test("a disabled account can still clear its saved password", () => {
  const page = settings(0);
  const clear = $(page).find("#RUTracker_lmclear_password");
  expect(clear.prop("disabled")).toBe(false);
  clear.prop("checked", true).trigger("change");
  const request = new window.rTorrentStub();
  request.setacc();
  expect(new URLSearchParams(request.content).get("RUTracker_clear_password")).toBe("1");
});
