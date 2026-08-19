import { readdirSync, readFileSync } from "fs";

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
      "absorbed|12<x", // digits followed by junk: parseInt would accept it
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

/**
 * The sentences above are only renderable if every language file supplies the
 * whole vocabulary in the shape init.js expects: chkMessages keyed by the
 * CHKMSG_* tokens that need a sentence (absorbed does not -- init.js builds a
 * URL for it), each with the single %s that carries the parameter, and a
 * chkResults entry for every chk-state. A file that drops a key or a
 * placeholder renders an empty detail rather than failing loudly, so the files
 * are checked here instead of at the user's screen.
 */
describe("language files", () => {
  const SENTENCE_TOKENS = ["superseded", "deleting", "topic-status", "fuse"];
  // check.php numbers chk-state 1..10, and init.js indexes chkResults by
  // chkstate-1.
  const CHK_STATES = 10;
  const languages = readdirSync("../plugins/rutracker_check/lang")
    .filter((name) => name.endsWith(".js"))
    .map((name) => name.replace(/\.js$/, ""));

  function load(language) {
    global.theUILang = {};
    new Function(
      readFileSync(`../plugins/rutracker_check/lang/${language}.js`, {
        encoding: "utf-8",
      })
    )();
    return global.theUILang;
  }

  it("covers every language the plugin ships", () => {
    expect(languages).toContain("en");
    expect(languages).toContain("ru");
    expect(languages.length).toBeGreaterThan(20);
  });

  it.each(languages)("%s defines the whole chk vocabulary", (language) => {
    const lang = load(language);

    expect(Object.keys(lang.chkMessages).sort()).toEqual(
      [...SENTENCE_TOKENS].sort()
    );
    for (const token of SENTENCE_TOKENS) {
      expect(lang.chkMessages[token].match(/%s/g)).toHaveLength(1);
    }

    expect(lang.chkResults).toHaveLength(CHK_STATES);
    for (const result of lang.chkResults) {
      expect(typeof result).toBe("string");
      expect(result.trim()).not.toBe("");
    }
  });
});

describe("WebUI wiring", () => {
  beforeEach(() => useLanguage("en"));

  it("registers the three chk-* columns and routes their values onto the torrent", () => {
    const captured = [];
    const savedAddRequest = global.theRequestManager.addRequest;
    const savedConfig = plugin.config;
    global.dStatus = { error: 128 };
    global.theRequestManager.addRequest = (target, key, callback) => {
      captured.push({ target, key, callback });
      return captured.length;
    };
    let chained = false;
    plugin.config = function () {
      chained = true;
    };
    try {
      global.theWebUI.config();

      // A misspelled custom key here would silently ship a dead pipeline:
      // rTorrent answers nothing for it, the callbacks never fire, and every
      // rendering test below still passes on hand-built torrents.
      expect(captured.map((c) => c.key)).toEqual([
        "chk-state",
        "chk-time",
        "chk-msg",
      ]);
      expect(captured.every((c) => c.target === "trt")).toBe(true);
      expect(chained).toBe(true);

      const byKey = Object.fromEntries(captured.map((c) => [c.key, c.callback]));
      const flagged = { state: 0 };
      byKey["chk-state"]("A".repeat(40), flagged, "10"); // STE_ABSORBED
      expect(flagged.chkstate).toBe("10");
      expect(flagged.state & 128).toBe(128);

      const healthy = { state: 0 };
      byKey["chk-state"]("A".repeat(40), healthy, "3"); // STE_UPTODATE
      expect(healthy.state & 128).toBe(0);

      const carrier = {};
      byKey["chk-time"]("A".repeat(40), carrier, "1234");
      byKey["chk-msg"]("A".repeat(40), carrier, "superseded|" + "B".repeat(40));
      expect(carrier.chktime).toBe("1234");
      expect(carrier.chkmsg).toBe("superseded|" + "B".repeat(40));
    } finally {
      global.theRequestManager.addRequest = savedAddRequest;
      plugin.config = savedConfig;
      delete global.dStatus;
    }
  });

  it("paints a deleted/absorbed torrent as an error row once the UI is ready, and stays out of the way otherwise", () => {
    const savedBase = plugin.getStatusIcon;
    plugin.getStatusIcon = () => ["Status_Base", "base"];
    try {
      plugin.allStuffLoaded = true;
      expect(global.theWebUI.getStatusIcon(torrent(10, ""))).toEqual([
        "Status_Error",
        plugin.chkResultText(torrent(10, "")),
      ]);
      expect(global.theWebUI.getStatusIcon(torrent(3, ""))).toEqual([
        "Status_Base",
        "base",
      ]); // a healthy state falls through to the WebUI's own icon

      plugin.allStuffLoaded = false;
      expect(global.theWebUI.getStatusIcon(torrent(10, ""))).toEqual([
        "Status_Base",
        "base",
      ]); // never claim an error row before the torrent list is fully loaded
    } finally {
      plugin.getStatusIcon = savedBase;
      plugin.allStuffLoaded = false;
    }
  });
});
