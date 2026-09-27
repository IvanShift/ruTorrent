const fs = require("fs");
const path = require("path");
const vm = require("vm");

test("Force Port prompts for the listening port when advertised ports differ", () => {
  let promptedPort;
  const plugin = { loadLang() {}, loadMainCSS() {} };
  const context = vm.createContext({
    plugin,
    rTorrentStub: function() {},
    theUILang: { portStatus: { 0: "unknown" } },
    prompt(_message, port) { promptedPort = port; return null; },
  });
  const source = fs.readFileSync(path.join(__dirname, "../../plugins/check_port/init.js"), "utf8");
  vm.runInContext(source, context);
  vm.runInContext(`
    const el = {
      removeClass() { return this; }, addClass() { return this; },
      hide() { return this; }, text() { return this; }, prop() { return this; }
    };
    for (const key of ["iconIPv4", "iconIPv6", "textIPv4", "textIPv6", "separator", "pane"])
      a[key] = el;
  `, context);
  plugin.getPortStatus({
    listen_port: 45001, ipv4_port: 46001, ipv6_port: 46002,
    ipv4: "-", ipv6: "-", ipv4_status: -1, ipv6_status: -1,
  });
  plugin.forcePort();
  expect(promptedPort).toBe(45001);
});
