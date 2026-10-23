import { readFileSync } from "fs";

window.$ = require("jquery");

// js/objects.js builds a context menu entry from a two-element array. When the
// second element is a string it runs it through eval on click (js/objects.js:400);
// when it is a function it calls it (js/objects.js:403). This stands in for that
// switch, so the spec exercises the sink the menu really uses.
function activate(entry) {
  const action = entry[1];
  if (typeof action === "string") {

    return eval(action);
  }
  return action();
}

// A label that ends the argument, runs a statement of its own and comments out
// the rest of the emitted call -- the shape a string action is vulnerable to.
const PAYLOAD = "x');window.__ran = 'YES';//";

function stubGlobals() {
  window.theUILang = new Proxy({}, { get: (_t, prop) => prop });
  window.theDialogManager = {
    show: () => {},
    hide: () => {},
    make: () => {},
    setHandler: () => {},
    setLayer: () => {},
  };
  window.thePlugins = { get: () => ({}), isInstalled: () => false };
  window.theContextMenu = { add: () => {}, get: () => null };
  window.injectCustomElementCSS = () => {};
  window.escapeHTML = (v) => String(v ?? "");
  window.$$ = () => null;
  // js/common.js:13
  window.$type = (obj) =>
    obj == undefined ? false : obj.constructor == Array ? "array" : typeof obj;
}

// The plugin body runs inside a closure holding `plugin`, the way the loader
// runs it. canChangeMenu has to answer true: it is what gates the entry.
function loadTracklabels() {
  const path = "../plugins/tracklabels/init.js";
  const code = readFileSync(path, { encoding: "utf-8" });
  const el = document.createElement("script");
  el.textContent =
    "(function(){ var plugin = new Proxy({ name: 'tracklabels', enabled: true," +
    " dialogId: 'dlg', canChangeMenu: () => true, canChangeColumns: () => false }," +
    " { has: () => true, get: (t, p) => (p in t ? t[p] : () => {})," +
    "   set: (t, p, v) => { t[p] = v; return true; } });" +
    "window.__tracklabels = plugin;" +
    "\ntry {\n" + code + "\n} catch(e) { window.__loadError = e; }\n})();";
  document.body.appendChild(el);
}

describe("tracklabels context menu entry", () => {
  let dialogCalls;

  beforeEach(() => {
    document.body.innerHTML = "";
    delete window.__ran;
    delete window.__loadError;
    dialogCalls = [];
    stubGlobals();
    window.__coreConfig = jest.fn();
    window.theWebUI = {
      categoryList: {
        refreshPanel: { plabel: attribs => [...attribs] },
        contextMenuEntries: () => [],
        updatedStatisticEntry: (_panel, id, attribs = {}) => [id, attribs],
      },
      config: window.__coreConfig,
      addTrackers: () => {},
      getTable: () => ({}),
      request: () => {},
    };
    loadTracklabels();
    expect(window.__loadError).toBeUndefined();
    // The entry's job is to open the dialog on this label and nothing else.
    window.theWebUI.showTracklabelsDialog = (lbl) => dialogCalls.push(lbl);
  });

  function editEntry(panelId, labelId) {
    const entries = theWebUI.categoryList.contextMenuEntries(panelId, labelId);
    expect(entries.length).toBe(1);
    return entries[0];
  }

  it("does not run a tracker name as code when the entry is activated", () => {
    activate(editEntry("ptrackers", "i" + PAYLOAD));
    expect(window.__ran).toBeUndefined();
    expect(dialogCalls).toEqual([PAYLOAD]);
  });

  it("does not run a torrent label as code when the entry is activated", () => {
    activate(editEntry("plabel", "clabel__" + PAYLOAD));
    expect(window.__ran).toBeUndefined();
    expect(dialogCalls).toEqual([PAYLOAD]);
  });

  it("passes a label through whole, whatever it contains", () => {
    const awkward = "a'b\"c\\d\ne</script>f";
    activate(editEntry("plabel", "clabel__" + awkward));
    expect(dialogCalls).toEqual([awkward]);
  });

  it("still opens the dialog on an ordinary label", () => {
    activate(editEntry("ptrackers", "iexample.com"));
    expect(dialogCalls).toEqual(["example.com"]);
    activate(editEntry("plabel", "plabel_other"));
    expect(dialogCalls).toEqual(["example.com", "nlb"]);
  });

  it("adds no entry for the all-labels row", () => {
    expect(theWebUI.categoryList.contextMenuEntries("plabel", "plabel_all")).toEqual([]);
    expect(theWebUI.categoryList.contextMenuEntries("ptrackers", "ptrackers_all")).toEqual([]);
  });

  it("still configures the core when column changes are forbidden", () => {
    const receiver = {};
    const settings = { restricted: true };
    theWebUI.config.call(receiver, settings);
    expect(window.__coreConfig).toHaveBeenCalledTimes(1);
    expect(window.__coreConfig.mock.contexts[0]).toBe(receiver);
    expect(window.__coreConfig).toHaveBeenCalledWith(settings);
  });

  it.each(['ordinary label', 'constructor', '__proto__'])("renders a literal label icon and its upload suffix for %s", label => {
    const attribs = new Map([['clabel__' + label, { text: label }]]);
    const icon = () => theWebUI.categoryList.refreshPanel.plabel(attribs)[0][1].icon;
    const uri = 'url:plugins/tracklabels/action.php?label=' + encodeURIComponent(label);
    expect(icon()).toBe(uri);
    window.__tracklabels.imageEditSuffix.label[label] = '&t=123';
    expect(icon()).toBe(uri + '&t=123');
  });

  it.each(['ordinary tracker', 'constructor', '__proto__'])("renders a literal tracker icon and its upload suffix for %s", tracker => {
    theWebUI.trackerNames = [tracker];
    window.__tracklabels.faviconRequests.add(tracker);
    const icon = () => theWebUI.categoryList.refreshPanel.ptrackers()[1][1].icon;
    const uri = 'url:plugins/tracklabels/action.php?tracker=' + encodeURIComponent(tracker);
    expect(icon()).toBe(uri);
    window.__tracklabels.imageEditSuffix.tracker[tracker] = '&t=123';
    expect(icon()).toBe(uri + '&t=123');
  });
});
