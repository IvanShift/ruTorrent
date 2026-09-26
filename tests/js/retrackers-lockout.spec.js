import { readFileSync } from "fs";

window.$ = require("jquery");

function loadScript(source) {
  const script = document.createElement("script");
  script.textContent = source;
  document.body.appendChild(script);
}

function loadWebUI() {
  window.theUILang = new Proxy({}, { get: (_target, prop) => prop });
  window.theFormatter = {};
  window.TYPE_STRING = "string";
  window.TYPE_NUMBER = "number";
  window.TYPE_PROGRESS = "progress";
  window.TYPE_PEERS = "peers";
  window.TYPE_SEEDS = "seeds";
  window.ALIGN_RIGHT = "right";
  window.dxSTable = function () {};
  window.rSpeedGraph = function () {};
  window.rSpeedGraph.prototype.addData = jest.fn();
  window.Timer = function () {};
  loadScript(readFileSync("../js/plugins.js", "utf-8"));
  let code = readFileSync("../js/webui.js", "utf-8");
  code = code.replace(/\n\$\(document\)\.ready\(function\(\)\n\{[\s\S]*?\n\}\);\s*$/, "");
  loadScript(code);
}

describe("retrackers failed-init cancellation", () => {
  beforeEach(() => {
    document.body.innerHTML = "";
    loadWebUI();
    theWebUI.getTable = jest.fn(() => ({ rowSel: { _plg_retrackers: true } }));
    theWebUI.request = jest.fn();
  });

  it("offers a done attempt for a disabled retrackers plugin with a recovery owner", () => {
    const plugin = new rPlugin("retrackers", 5.1, "test", "test", 0x0100, "");
    plugin.disable();
    plugin.shutdownWhileDisabled = true;
    theWebUI.plgShutdown();
    expect(theWebUI.request).toHaveBeenCalledTimes(1);
    expect(theWebUI.request.mock.calls[0][0]).toContain("&hash=retrackers");
  });


  it("shows shutdown in the plugin menu for the disabled recovery owner", () => {
    const plugin = new rPlugin("retrackers", 5.1, "test", "test", 0x0100, "");
    plugin.disable();
    plugin.shutdownWhileDisabled = true;
    window.$type = (value) => typeof value;
    window.CMENU_CHILD = "child";
    window.theContextMenu = {
      clear: jest.fn(),
      add: jest.fn(),
      show: jest.fn(),
    };
    theWebUI.getTable = jest.fn(() => ({ selCount: 1 }));
    theWebUI.plgSelect({ which: 3 }, "_plg_retrackers");
    expect(theContextMenu.add.mock.calls[0][0][1]).toBe("theWebUI.plgShutdown()");
  });

  it("stops offering shutdown after the plugin was removed", () => {
    const plugin = new rPlugin("retrackers", 5.1, "test", "test", 0x0100, "");
    plugin.disable();
    plugin.shutdownWhileDisabled = true;
    plugin.remove();
    expect(plugin.shutdownWhileDisabled).toBe(false);
    theWebUI.plgShutdown();
    expect(theWebUI.request).not.toHaveBeenCalled();
  });

  it("keeps unrelated disabled plugins out of the shutdown request", () => {
    const plugin = new rPlugin("retrackers", 5.1, "test", "test", 0x0100, "");
    plugin.disable();
    theWebUI.plgShutdown();
    expect(theWebUI.request).not.toHaveBeenCalled();
  });
});
