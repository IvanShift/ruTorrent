import { readFileSync } from "fs";

/**
 * Tests for plugins/rutracker_check/init.js's chk-msg rendering.
 *
 * chk-msg carries a machine token written by PHP ("<token>|<parameter>", see
 * ruTrackerChecker's CHKMSG_* constants); the sentence itself lives in the
 * plugin's language files, so this is where the two halves meet. init.js is
 * evaluated with a hand-built `plugin` object and the handful of globals it
 * touches at load time -- it is a plain script, not a module, and none of the
 * WebUI machinery it hooks into is needed to render a string.
 */

window.$ = require("jquery");

const initCode = readFileSync("../plugins/rutracker_check/init.js", {
  encoding: "utf-8",
});
const langCode = {
  en: readFileSync("../plugins/rutracker_check/lang/en.js", {
    encoding: "utf-8",
  }),
  ru: readFileSync("../plugins/rutracker_check/lang/ru.js", {
    encoding: "utf-8",
  }),
};

let plugin;

function useLanguage(language) {
  global.theUILang = {};
  new Function(langCode[language])();
}

// chk-state 7 is STE_NOT_NEED, 10 is STE_ABSORBED, 5 is
// STE_CANT_REACH_TRACKER (check.php).
function torrent(chkstate, chkmsg) {
  return { chkstate: chkstate, chkmsg: chkmsg };
}

beforeAll(() => {
  global.thePlugins = { get: () => ({ langLoaded: () => {} }) };
  global.theWebUI = {
    createMenu: () => {},
    config: () => {},
    isTorrentCommandEnabled: () => {},
    getStatusIcon: () => {},
    updateDetails: () => {},
  };
  global.theRequestManager = {
    addRequest: () => 0,
    removeRequest: () => {},
    map: () => "",
  };
  plugin = {
    loadLang: () => {},
    canChangeMenu: () => false,
    enabled: true,
  };
  new Function("plugin", initCode)(plugin);
});

describe("chkResultText", () => {
  beforeEach(() => useLanguage("en"));

  it("renders a token with its parameter substituted, after the status label", () => {
    const hash = "B".repeat(40);
    expect(plugin.chkResultText(torrent(7, "superseded|" + hash))).toBe(
      "No need — The current version of this topic is already in the client: " +
        hash
    );
  });

  it("renders the deletion counter, the topic status and the fuse host", () => {
    expect(plugin.chkMessageText("deleting|2/3")).toBe(
      "The topic is missing from the forum list; confirmation cycle 2/3"
    );
    expect(plugin.chkMessageText("topic-status|5")).toBe(
      "Topic status 5: closed, not approved, or a duplicate"
    );
    expect(plugin.chkMessageText("fuse|bt2.t-ru.org")).toBe(
      "Tracker bt2.t-ru.org looks unavailable; the check is postponed"
    );
  });

  it("turns an absorbed topic id into a plain URL, with no sentence of its own", () => {
    expect(plugin.chkResultText(torrent(10, "absorbed|42"))).toBe(
      "Absorbed by another topic — resolve manually — https://rutracker.org/forum/viewtopic.php?t=42"
    );
    expect(theUILang.chkMessages.absorbed).toBeUndefined();
  });

  it("renders the same tokens in the viewer's own language", () => {
    useLanguage("ru");
    expect(plugin.chkMessageText("deleting|1/3")).toBe(
      "Раздачи нет в списке форума; цикл подтверждения 1/3"
    );
    expect(plugin.chkResultText(torrent(10, "absorbed|42"))).toBe(
      "Поглощена другой раздачей — разберитесь вручную — https://rutracker.org/forum/viewtopic.php?t=42"
    );
  });

  it("shows the status label alone for an unknown or malformed token", () => {
    for (const message of [
      "definitely-not-a-token|x",
      "superseded",
      "|x",
      "deleting|",
      "absorbed|not-a-number",
      "|",
    ]) {
      expect(plugin.chkResultText(torrent(7, message))).toBe("No need");
    }
  });

  it("never renders undefined, whatever the state or the message", () => {
    expect(plugin.chkResultText(torrent(99, "deleting|1/3"))).toBe(
      "The topic is missing from the forum list; confirmation cycle 1/3"
    );
    expect(plugin.chkResultText(torrent(99, "garbage"))).toBe("");
    expect(plugin.chkResultText(torrent(7, ""))).toBe("No need");
  });

  it("inserts the parameter literally, never as a replacement pattern", () => {
    expect(plugin.chkMessageText("fuse|$&$1")).toBe(
      "Tracker $&$1 looks unavailable; the check is postponed"
    );
  });

  it("stays plain text: the result is used as a tooltip and via .text()", () => {
    expect(plugin.chkMessageText("fuse|<b>x</b>")).not.toContain("&lt;");
    expect(plugin.chkResultText(torrent(10, "absorbed|42"))).not.toContain("<");
  });
});
