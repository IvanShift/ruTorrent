import { readFileSync } from "fs";

window.$ = require("jquery");

// The exhausted-queue notice: init.php emits plugin.terminalJobs, init.js shows
// it once when the plugin's language file has loaded.

// The form RetrackersWorkerRpcAdapter::retryResetCommand() prints inside the image.
const COMMAND = "docker exec -u torrent <container> php /rutorrent/app/tools/retrackers-retry-reset.php --user=alice";
const ENGLISH = "Retrackers: queued jobs that used up their retries: 2. They are no longer retried automatically. " +
  "After fixing the cause, run " + COMMAND + " on the server to list them, then rerun it with a listed hash and --apply to reset one.";

function loadScript(source) {
  const script = document.createElement("script");
  script.textContent = source;
  document.body.appendChild(script);
}

function notice(count, command = COMMAND) {
  return "plugin.terminalJobs = " + JSON.stringify({ count, command }) + ";\n";
}

// php/getplugins.php composition: one closure with the init.php output, then init.js.
function initRetrackers(initOutput, restrictions = thePlugins.restictions.cantChangeOptions) {
  const plugin = new rPlugin("retrackers", 5.1, "test", "test", restrictions, "");
  plugin.loadMainCSS = jest.fn();
  plugin.loadLang = jest.fn();
  plugin.attachPageToOptions = jest.fn();
  window.retrackersUnderTest = plugin;
  loadScript("(function () { var plugin = window.retrackersUnderTest;\n" + initOutput + "\n" +
    readFileSync("../plugins/retrackers/init.js", "utf-8") + "\n})();");
  return plugin;
}

// The shipped language file ends with thePlugins.get("retrackers").langLoaded().
function loadLanguage(lang, edit = (source) => source) {
  loadScript(edit(readFileSync("../plugins/retrackers/lang/" + lang + ".js", "utf-8")));
}

describe("retrackers exhausted queue notice", () => {
  beforeEach(() => {
    document.body.innerHTML = "";
    window.theUILang = {};
    window.noty = jest.fn();
    // js/common.js
    window.$type = (obj) => (obj == undefined) ? false : (obj.constructor == Array) ? "array" : typeof obj;
    window.theWebUI = { addAndShowSettings() {}, setSettings() {} };
    window.rTorrentStub = function () {};
    loadScript(readFileSync("../js/plugins.js", "utf-8"));
  });

  it("shows one notice with the count and the list command", () => {
    const plugin = initRetrackers(notice(2));
    expect(noty).not.toHaveBeenCalled();
    loadLanguage("en");
    expect(noty).toHaveBeenCalledTimes(1);
    expect(noty).toHaveBeenCalledWith(ENGLISH, "error");
    plugin.langLoaded();
    expect(noty).toHaveBeenCalledTimes(1);
  });

  it("shows nothing without terminal jobs", () => {
    initRetrackers("");
    loadLanguage("en");
    initRetrackers(notice(0));
    loadLanguage("en");
    expect(noty).not.toHaveBeenCalled();
  });

  it("shows the English text in a locale that has not translated it", () => {
    initRetrackers(notice(2));
    loadLanguage("de");
    expect(noty).toHaveBeenCalledTimes(1);
    expect(noty).toHaveBeenCalledWith(ENGLISH, "error");
  });

  it("still shows the notice with a language file that predates the string", () => {
    initRetrackers(notice(2));
    loadLanguage("de", (source) => {
      const stripped = source.replace(/\n theUILang\.retrackersTerminalJobs\t= "[^"\n]*";/, "");
      expect(stripped).not.toContain("retrackersTerminalJobs");
      return stripped;
    });
    expect(noty).toHaveBeenCalledTimes(1);
    expect(noty).toHaveBeenCalledWith(ENGLISH, "error");
  });

  it("uses the Russian translation with both values", () => {
    initRetrackers(notice(3));
    loadLanguage("ru");
    expect(noty).toHaveBeenCalledTimes(1);
    const [message, status] = noty.mock.calls[0];
    expect(status).toBe("error");
    expect(message).toMatch(/^Ретрекеры: /);
    expect(message).toContain(" 3; ");
    expect(message).toContain(" " + COMMAND + ", ");
    expect(message).not.toContain("%s");
  });

  it("inserts replacement patterns in the command literally", () => {
    const command = "php tools/retrackers-retry-reset.php --user=a$&b$'c$1";
    initRetrackers(notice(2, command));
    loadLanguage("en");
    expect(noty.mock.calls[0][0]).toBe(ENGLISH.replace(COMMAND, () => command));
  });

  it("keeps the options page and still shows the notice when options may change", () => {
    const plugin = initRetrackers(notice(2), 0);
    loadLanguage("en");
    expect(plugin.attachPageToOptions).toHaveBeenCalledTimes(1);
    expect(noty).toHaveBeenCalledTimes(1);
    expect(noty).toHaveBeenCalledWith(ENGLISH, "error");
  });
});
