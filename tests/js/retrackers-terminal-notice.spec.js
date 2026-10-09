import { readFileSync, readdirSync } from "fs";

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

function prepareRetrackers() {
  document.body.innerHTML = "";
  window.theUILang = {};
  window.noty = jest.fn();
  // js/common.js
  window.$type = (obj) => (obj == undefined) ? false : (obj.constructor == Array) ? "array" : typeof obj;
  window.theWebUI = { addAndShowSettings() {}, setSettings() {} };
  window.rTorrentStub = function () {};
  loadScript(readFileSync("../js/plugins.js", "utf-8"));
}

describe("retrackers exhausted queue notice", () => {
  beforeEach(prepareRetrackers);

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
  it("shows persistent storage refusal once with admission recovery", () => {
    const command = "docker exec -u torrent <container> php /rutorrent/app/tools/retrackers-apply-existing.php --user=alice";
    const plugin = initRetrackers("plugin.enqueueRefusals = " + JSON.stringify({ count: 2, command }) + ";");
    loadLanguage("en");
    expect(noty).toHaveBeenCalledTimes(1);
    const [message, status] = noty.mock.calls[0];
    expect(status).toBe("error");
    expect(message).toContain("session storage refused queue admission for 2 torrent(s)");
    expect(message).toContain(command);
    expect(message).toContain("--apply");
    expect(message).not.toContain("retry-reset");
    plugin.langLoaded();
    expect(noty).toHaveBeenCalledTimes(1);
  });

});

describe("retrackers enqueue refusal localization", () => {
  const command = "docker exec -u torrent <container> php /rutorrent/app/tools/retrackers-apply-existing.php --user=alice";
  const english = "Retrackers: session storage refused queue admission for 2 torrent(s). " +
    "Repair session storage, then run " + command + " on the server to review candidates and rerun with --apply. " +
    "Existing torrent metadata is preserved.";

  beforeEach(prepareRetrackers);

  function enqueueNotice(locale) {
    const plugin = initRetrackers("plugin.enqueueRefusals = " + JSON.stringify({ count: 2, command }) + ";");
    loadLanguage(locale);
    expect(noty).toHaveBeenCalledTimes(1);
    plugin.langLoaded();
    expect(noty).toHaveBeenCalledTimes(1);
    return noty.mock.calls[0];
  }

  it("shows the Russian storage repair guidance with count and canonical command", () => {
    expect(enqueueNotice("ru")).toEqual([
      "Ретрекеры: хранилище сессии отказало в постановке 2 раздач в очередь. " +
      "Восстановите хранилище сессии, затем выполните на сервере " + command +
      ", чтобы проверить кандидатов, и повторите команду с --apply. " +
      "Метаданные существующих раздач сохраняются.", "error",
    ]);
  });

  const fallbackLocales = readdirSync("../plugins/retrackers/lang")
    .filter(name => name.endsWith(".js") && name !== "en.js" && name !== "ru.js")
    .map(name => name.slice(0, -3));
  it.each(fallbackLocales)("shows the explicit English recovery fallback in %s", locale => {
    expect(enqueueNotice(locale)).toEqual([english, "error"]);
  });
});
