import { readFileSync } from "fs";

window.$ = require("jquery");
window.jQuery = window.$;

test("mobile keeps the deletion visible and displays a classified HTTP refusal", () => {
  document.body.innerHTML = '<div id="deleteWithData"><input type="checkbox" checked></div><div id="alert_placeholder"></div>';
  window.plugin = { disable: jest.fn() };
  window.theWebUI = {
    getTrackerName: () => "",
    requestWithTimeout: jest.fn(),
  };
  window.$type = value => typeof value;
  const script = document.createElement("script");
  script.textContent = readFileSync("../plugins/mobile/init.js", "utf8");
  document.body.appendChild(script);

  plugin.eraseWithDataLoaded = true;
  plugin.torrent = { hash: "A".repeat(40) };
  plugin.showList = jest.fn();
  plugin.showAlert = jest.fn();
  plugin.deleteConfirmed();

  const [query, success, , failure] = theWebUI.requestWithTimeout.mock.calls[0];
  expect(query).toBe("?action=removewithdata&hash=" + "A".repeat(40) + "&v=1");
  expect(plugin.torrent.hash).toBe("A".repeat(40));
  expect(plugin.showList).not.toHaveBeenCalled();

  failure("409 [error,removewithdata]", "Torrent busy; try again.");
  expect(plugin.showAlert).toHaveBeenCalledWith("Torrent busy; try again.", "alert-danger");
  expect(plugin.torrent.hash).toBe("A".repeat(40));
  expect(plugin.showList).not.toHaveBeenCalled();

  failure("500 [error,removewithdata]", "<img src=x onerror=alert(1)>");
  expect(plugin.showAlert).toHaveBeenLastCalledWith(
    "Deletion request failed. Check the server log.", "alert-danger");

  success({ refused: ["invalid-hash:string"] });
  expect(plugin.showAlert).toHaveBeenLastCalledWith(
    "Deletion partly refused: 1 torrent(s). Check the server log.", "alert-danger");
  expect(plugin.torrent.hash).toBe("A".repeat(40));
  expect(plugin.showList).not.toHaveBeenCalled();

  success({ retained: ["A".repeat(40)] });
  expect(plugin.showAlert).toHaveBeenLastCalledWith(
    "Deletion outcome unresolved for 1 torrent(s). Check the server log.", "alert-danger");
  expect(plugin.torrent).toBeUndefined();
  expect(plugin.showList).toHaveBeenCalledTimes(1);
});
