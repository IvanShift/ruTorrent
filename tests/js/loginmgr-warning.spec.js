import { readFileSync } from "fs";

window.$ = require("jquery");

function renderAccount(enabled) {
  document.body.innerHTML = "";
  window.theWebUI = {
    theAccounts: {
      YggTorrent: { enabled, configurationRequired: true, login: "", password: "", auto: 0 },
    },
  };
  window.theUILang = {
    Enabled: "Enabled", accAuto: "Auto", accLogin: "Login", accPassword: "Password",
    acAutoNone: "None", acAutoDay: "Day", acAutoWeek: "Week", acAutoMonth: "Month",
    accOriginRequired: "Configure Ygg origin", accAccounts: "Accounts",
  };
  window.linked = jest.fn();
  const attachPageToOptions = jest.fn();
  window.plugin = { loadLang() {}, canChangeOptions() { return false; }, attachPageToOptions };
  const script = document.createElement("script");
  script.textContent = readFileSync("../plugins/loginmgr/init.js", "utf8");
  document.body.appendChild(script);
  window.plugin.onLangLoaded();
  return attachPageToOptions.mock.calls[0][0];
}

test("Ygg origin warning follows the Enabled checkbox", () => {
  const disabledPage = renderAccount(0);
  const warning = disabledPage.querySelector(".alert-warning");
  expect(warning.style.display).toBe("none");
  $(disabledPage).find("#YggTorrent_lmenabled").prop("checked", true).trigger("change");
  expect(warning.style.display).not.toBe("none");
  $(disabledPage).find("#YggTorrent_lmenabled").prop("checked", false).trigger("change");
  expect(warning.style.display).toBe("none");
  const enabledPage = renderAccount(1);
  expect(enabledPage.querySelector(".alert-warning").textContent).toBe("Configure Ygg origin");
  expect(enabledPage.querySelector(".alert-warning").style.display).not.toBe("none");
});
