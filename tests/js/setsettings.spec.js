import { readFileSync } from "fs";

window.$ = require("jquery");

const SOURCES = [
  "../lang/en.js",
  "../js/common.js",
  "../js/content.js",
  "../js/rtorrent.js",
];

// correctContent() builds theRequestManager.aliases by $.extend-ing one block per
// rtorrent version, so the alias table can only ever grow. Reload the sources for
// each version under test to get a table that matches that version exactly.
function loadUI(iVersion) {
  window.theWebUI = {
    settings: { "webui.needmessage": true },
    showFlags: 0xffff,
    systemInfo: { rTorrent: { apiVersion: 24, iVersion, started: true } },
  };
  for (const src of SOURCES) {
    const scriptEl = document.createElement("script");
    scriptEl.textContent = readFileSync(src, { encoding: "utf-8" });
    document.body.appendChild(scriptEl);
  }
  correctContent();
}

function commandsFor(query) {
  return new rTorrentStub(query).commands.map((cmd) => [
    cmd.command,
    ...cmd.params.map((prm) => `${prm.type}:${prm.value}`),
  ]);
}

// The socket allocation becomes adjustable in 0.16.19, and every command this
// batch uses is still registered in 0.16.21 -- src/command_local.cc builds them
// in a loop over the socket categories -- so both ends of that range have to
// produce the same batch.
const V_SOCKET_ALLOC = [
  ["0.16.19", 0x1013],
  ["0.16.21", 0x1015],
];

describe.each(V_SOCKET_ALLOC)("setsettings on rtorrent %s with adjustable socket allocation", (_name, iVersion) => {
  beforeEach(() => loadUI(iVersion));

  it("sets both bounds and recomputes for max open files", () => {
    expect(commandsFor("?action=setsettings&s=nmax_open_files&v=20000")).toStrictEqual([
      ["system.sockets.files.min_alloc"],
      ["system.sockets.files.max_alloc"],
      ["system.sockets.files.min_alloc.set", "string:", "i8:20000"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:20000"],
      ["system.sockets.adjust_alloc"],
    ]);
  });

  it("sets both bounds and recomputes for max open http", () => {
    expect(commandsFor("?action=setsettings&s=nmax_open_http&v=1024")).toStrictEqual([
      ["system.sockets.http.min_alloc"],
      ["system.sockets.http.max_alloc"],
      ["system.sockets.http.min_alloc.set", "string:", "i8:1024"],
      ["system.sockets.http.max_alloc.set", "string:", "i8:1024"],
      ["system.sockets.adjust_alloc"],
    ]);
  });

  // max_alloc alone is a ceiling and can only lower the allocation, so a raise
  // needs min_alloc too. Neither takes effect before adjust_alloc runs.
  it("emits adjust_alloc once at the end when both settings change together", () => {
    const commands = commandsFor(
      "?action=setsettings&s=nmax_open_files&v=20000&s=nmax_open_http&v=1024"
    );
    expect(commands).toStrictEqual([
      ["system.sockets.files.min_alloc"],
      ["system.sockets.files.max_alloc"],
      ["system.sockets.http.min_alloc"],
      ["system.sockets.http.max_alloc"],
      ["system.sockets.files.min_alloc.set", "string:", "i8:20000"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:20000"],
      ["system.sockets.http.min_alloc.set", "string:", "i8:1024"],
      ["system.sockets.http.max_alloc.set", "string:", "i8:1024"],
      ["system.sockets.adjust_alloc"],
    ]);
    expect(commands.filter(([name]) => name === "system.sockets.adjust_alloc")).toHaveLength(1);
  });

  it("does not recompute when no socket setting changed", () => {
    expect(commandsFor("?action=setsettings&s=nmax_peers&v=100")).toStrictEqual([
      ["throttle.max_peers.normal.set", "string:", "i8:100"],
    ]);
  });

  it("keeps the dht setting on its own branch", () => {
    expect(commandsFor("?action=setsettings&s=ndht&v=0")).toStrictEqual([
      ["dht.mode.set", "string:", "string:disable"],
    ]);
    expect(commandsFor("?action=setsettings&s=ndht&v=1")).toStrictEqual([
      ["dht.mode.set", "string:", "string:auto"],
    ]);
  });

  it("leaves other settings in the batch untouched", () => {
    expect(
      commandsFor("?action=setsettings&s=nmax_open_files&v=20000&s=nmax_uploads&v=50")
    ).toStrictEqual([
      ["system.sockets.files.min_alloc"],
      ["system.sockets.files.max_alloc"],
      ["system.sockets.files.min_alloc.set", "string:", "i8:20000"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:20000"],
      ["throttle.max_uploads.set", "string:", "i8:50"],
      ["system.sockets.adjust_alloc"],
    ]);
  });
});

describe("settings read-back", () => {
  function readCommands(iVersion) {
    loadUI(iVersion);
    return commandsFor("?action=getsettings").map(([name]) => name);
  }

  // max_alloc is only the ceiling and commonly sits orders of magnitude above
  // the allocation in use, so reading it back would misreport the limit.
  it("reads the allocation in effect once the socket manager owns the limits", () => {
    const commands = readCommands(0x1010);
    expect(commands).toContain("system.sockets.http.max_size");
    expect(commands).not.toContain("system.sockets.http.max_alloc");
    expect(commands).toContain("network.max_open_files");
  });

  it("keeps the legacy read-back commands on 0.9.8", () => {
    const commands = readCommands(0x908);
    expect(commands).toContain("network.max_open_files");
    expect(commands).toContain("network.http.max_open");
  });
});

// Sending these commands to an rtorrent that aborts on an over-budget
// adjust_alloc would kill the process, so older versions keep the old command.
describe("setsettings below the socket allocation version gate", () => {
  it("sends the single ceiling command on 0.16.18", () => {
    loadUI(0x1012);
    expect(commandsFor("?action=setsettings&s=nmax_open_files&v=20000")).toStrictEqual([
      ["system.sockets.files.max_alloc.set", "string:", "i8:20000"],
    ]);
  });

  it("sends the legacy command on 0.9.8", () => {
    loadUI(0x908);
    expect(commandsFor("?action=setsettings&s=nmax_open_files&v=20000")).toStrictEqual([
      ["network.max_open_files.set", "string:", "i8:20000"],
    ]);
    expect(commandsFor("?action=setsettings&s=nmax_open_http&v=1024")).toStrictEqual([
      ["network.http.max_open.set", "string:", "i8:1024"],
    ]);
  });
});

// js/webui.js decides the setsettings key prefix from the input element alone:
// a checkbox, a select or anything carrying the num class goes out under "n",
// everything else under "s". Both socket-allocation shims key on "n" only --
// getSocketAllocCategory() above and its PHP twin in php/settings.php -- and
// the "n" prefix is also what makes the value travel as a number rather than a
// string. An input[type=number] matches neither input:checkbox nor select, so
// the numeric fields in the options window have to say so themselves.
describe("the wire keys the options window produces", () => {
  let optionsLoaded = false;

  function loadOptionsWindow() {
    // theUILang answers every key with the key itself: these tests are about
    // element ids and wire keys, not about any one language's wording.
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
    window.bootstrap = { Tab: { getOrCreateInstance: () => ({ show() {} }) } };
    // The language select is built from this table; one entry is enough.
    window.AvailableLanguages = { en: "English" };

    const sources = ["../js/common.js", "../js/webui.js"];
    // js/options.js declares theOptionsWindow with const, which a second load
    // into the same document would refuse as a redeclaration. That binding
    // survives reloads of everything else, so load it once and let the rest be
    // rebuilt around it.
    if (!optionsLoaded) {
      sources.push("../js/options.js");
      optionsLoaded = true;
    }
    for (const src of sources) {
      let code = readFileSync(src, { encoding: "utf-8" });
      // The document-ready block wires the live page together; the options
      // window and the settings writer under test do not need it.
      code = code.replace(/\n\$\(document\)\.ready\(function\(\)\n\{[\s\S]*?\n\}\);\s*$/, "");
      const scriptEl = document.createElement("script");
      scriptEl.textContent = code;
      document.body.appendChild(scriptEl);
    }
  }

  // The five numeric fields of the "other limiting" block, with a value that
  // differs from the one theWebUI thinks is in effect so that each one is
  // reported as changed.
  const CHANGED = {
    max_uploads_global: [10, 12],
    max_downloads_global: [10, 12],
    max_memory_usage: [512, 1024],
    max_open_files: [1024, 20000],
    max_open_http: [32, 64],
  };

  function settingsRequestURI() {
    document.body.innerHTML =
      '<div id="stg_c"><div class="list-group"></div><div id="st_btns"></div></div>';
    loadOptionsWindow();
    theOptionsWindow.init();
    theWebUI.settings = {};
    for (const id in CHANGED) {
      theWebUI.settings[id] = CHANGED[id][0];
      $("#" + $.escapeSelector(id)).val(CHANGED[id][1]);
    }
    // setSettings() only sends when it believes rtorrent is up.
    theWebUI.systemInfo = { rTorrent: { started: true } };
    theWebUI.request = jest.fn();
    theWebUI.setSettings();
    expect(theWebUI.request).toHaveBeenCalled();
    return theWebUI.request.mock.calls[0][0];
  }

  it("numbers every field of the other-limiting block", () => {
    const keys = settingsRequestURI()
      .split("&")
      .filter((part) => part.indexOf("s=") === 0)
      .map((part) => part.substr(2))
      .sort();
    expect(keys).toStrictEqual([
      "nmax_downloads_global",
      "nmax_memory_usage",
      "nmax_open_files",
      "nmax_open_http",
      "nmax_uploads_global",
    ]);
  });

  // The shim that turns a socket setting into min_alloc/max_alloc/adjust_alloc
  // matches on the emitted name, so the options window can only reach that path
  // once the name it emits carries the prefix the shim looks for. The other two
  // fields of the block never reach that shim, but the same prefix moved them
  // from <string> to <i8>, which is the half of the change with the further to
  // fall: pin the whole batch, parameter types and all, rather than the names.
  it("turns the whole other-limiting block into the batch rtorrent expects", () => {
    const uri = settingsRequestURI();
    expect(uri).toBe(
      "?action=setsettings&s=nmax_uploads_global&v=12&s=nmax_downloads_global&v=12" +
        "&s=nmax_memory_usage&v=1073741824&s=nmax_open_files&v=20000&s=nmax_open_http&v=64"
    );
    loadUI(V_SOCKET_ALLOC[0][1]);
    expect(commandsFor(uri)).toStrictEqual([
      ["system.sockets.files.min_alloc"],
      ["system.sockets.files.max_alloc"],
      ["system.sockets.http.min_alloc"],
      ["system.sockets.http.max_alloc"],
      ["throttle.max_uploads.global.set", "string:", "i8:12"],
      ["throttle.max_downloads.global.set", "string:", "i8:12"],
      ["pieces.memory.max.set", "string:", "i8:1073741824"],
      ["system.sockets.files.min_alloc.set", "string:", "i8:20000"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:20000"],
      ["system.sockets.http.min_alloc.set", "string:", "i8:64"],
      ["system.sockets.http.max_alloc.set", "string:", "i8:64"],
      ["system.sockets.adjust_alloc"],
    ]);
  });
});

// js/webui.js sends a setting whose field no longer matches what it holds in
// theWebUI.settings, and a numeric field the user cleared matches nothing, so it
// goes out as "&v=". An <i8> with nothing in it is not a number rtorrent can
// decode, and it refuses the whole methodCall over that rather than faulting the
// one member -- one cleared field would take every other setting in the press
// down with it. plugins/httprpc/action.php puts every "n" value through
// floatval() before it builds a command, so its door answers a cleared field
// with 0; this door has to answer it the same way.
describe("a numeric setting the user cleared", () => {
  beforeEach(() => loadUI(V_SOCKET_ALLOC[0][1]));

  it("goes out as a zero rather than as an empty i8", () => {
    expect(commandsFor("?action=setsettings&s=nmax_uploads_global&v=")).toStrictEqual([
      ["throttle.max_uploads.global.set", "string:", "i8:0"],
    ]);
  });

  // The command list keeps the value the caller handed over; what rtorrent has
  // to decode is the request body built from it.
  it("puts a number in the request body", () => {
    const content = new rTorrentStub("?action=setsettings&s=nmax_uploads_global&v=").content;
    expect(content).toContain("<i8>0</i8>");
    expect(content).not.toContain("<i8></i8>");
  });

  it("carries the same zero through the socket allocation shim", () => {
    expect(commandsFor("?action=setsettings&s=nmax_open_files&v=")).toStrictEqual([
      ["system.sockets.files.min_alloc"],
      ["system.sockets.files.max_alloc"],
      ["system.sockets.files.min_alloc.set", "string:", "i8:0"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:0"],
      ["system.sockets.adjust_alloc"],
    ]);
  });

  // Only an "n" key is a number in the first place: an "s" key with nothing in
  // it is an empty string, which is a perfectly decodable one.
  it("leaves a cleared string setting a string", () => {
    expect(commandsFor("?action=setsettings&s=sdirectory&v=")).toStrictEqual([
      ["directory.default.set", "string:", "string:"],
    ]);
  });
});

// A refused adjust_alloc keeps the min_alloc/max_alloc it was handed staged, and
// staged bounds also break every recompute after it, so what was in effect has
// to go back. plugins/httprpc/action.php guards its own adjust_alloc that way in
// getSocketAlloc() / restoreSocketAlloc(); this is the same guard on the door the
// browser uses when the httprpc plugin is not carrying the request -- including
// the part of it that reaches further than the recompute, which the last test
// here pins.
describe.each(V_SOCKET_ALLOC)("a setsettings batch rtorrent %s faults", (_name, iVersion) => {
  beforeEach(() => loadUI(iVersion));

  // system.multicall answers member by member and embeds a member's fault in its
  // own slot rather than abandoning the batch, so the reads at the head of the
  // batch still come back when a later member is refused. Whitespace between the
  // tags would be parsed as a value, so this is built as one line.
  function value(v) {
    return `<value><array><data><value><i8>${v}</i8></value></data></array></value>`;
  }

  function fault(message) {
    return (
      "<value><struct>" +
      "<member><name>faultCode</name><value><i4>-501</i4></value></member>" +
      `<member><name>faultString</name><value><string>${message}</string></value></member>` +
      "</struct></value>"
    );
  }

  function multicallResponse(members) {
    return new DOMParser().parseFromString(
      '<?xml version="1.0" encoding="UTF-8"?><methodResponse><params><param><value><array><data>' +
        members.join("") +
        "</data></array></value></param></params></methodResponse>",
      "text/xml"
    );
  }

  // The bounds in effect before the batch, then one answer per staged write, then
  // the verdict on the recompute.
  function answer(bounds, verdict) {
    return multicallResponse(
      bounds.map(value).concat(bounds.map(() => value(0))).concat([verdict])
    );
  }

  function restoreRequest(query, response) {
    const stub = new rTorrentStub(query);
    window.Ajax = jest.fn();
    stub.getResponse(response);
    return window.Ajax.mock.calls.map(([uri]) => uri);
  }

  it("puts the bounds that were in effect back", () => {
    const requests = restoreRequest(
      "?action=setsettings&s=nmax_open_files&v=20000",
      answer([1024, 4096], fault("Socket allocation over budget"))
    );
    expect(requests).toHaveLength(1);
    expect(commandsFor(requests[0])).toStrictEqual([
      ["system.sockets.files.min_alloc.set", "string:", "i8:1024"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:4096"],
      ["system.sockets.adjust_alloc"],
    ]);
  });

  it("puts every category the batch touched back", () => {
    const requests = restoreRequest(
      "?action=setsettings&s=nmax_open_files&v=20000&s=nmax_open_http&v=1024",
      answer([1024, 4096, 32, 64], fault("Socket allocation over budget"))
    );
    expect(requests).toHaveLength(1);
    expect(commandsFor(requests[0])).toStrictEqual([
      ["system.sockets.files.min_alloc.set", "string:", "i8:1024"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:4096"],
      ["system.sockets.http.min_alloc.set", "string:", "i8:32"],
      ["system.sockets.http.max_alloc.set", "string:", "i8:64"],
      ["system.sockets.adjust_alloc"],
    ]);
  });

  // A read that faults answers with a struct where a number was expected, and it
  // shifts every value behind it as well, so there is nothing left worth putting
  // back -- the same reason the PHP side abandons the restore when its read does
  // not return one value per bound.
  it("puts nothing back when a bound did not come back as a number", () => {
    expect(
      restoreRequest(
        "?action=setsettings&s=nmax_open_files&v=20000",
        multicallResponse([
          fault("Method 'system.sockets.files.min_alloc' not defined"),
          value(4096),
          value(0),
          value(0),
          fault("Socket allocation over budget"),
        ])
      )
    ).toStrictEqual([]);
  });

  it("leaves an accepted recompute alone", () => {
    expect(
      restoreRequest(
        "?action=setsettings&s=nmax_open_files&v=20000",
        answer([1024, 4096], value(0))
      )
    ).toStrictEqual([]);
  });

  // What sends the bounds back is a faulted request, not a faulted recompute:
  // rtorrent accepts the socket change and the recompute here and refuses only
  // the unrelated setting pressed with them, and the socket change still goes
  // back. That is the reach getSocketAlloc() / restoreSocketAlloc() have on the
  // PHP door -- plugins/httprpc/action.php restores on the success of the whole
  // request -- and the two doors have to answer one request the same way, so
  // this is pinned as intended rather than left to drift.
  it("puts the bounds back over a fault in a setting that is not the recompute", () => {
    const requests = restoreRequest(
      "?action=setsettings&s=nmax_open_files&v=20000&s=nmax_uploads&v=50",
      multicallResponse([
        value(1024),
        value(4096),
        value(0),
        value(0),
        fault("Could not set throttle"),
        value(0),
      ])
    );
    expect(requests).toHaveLength(1);
    expect(commandsFor(requests[0])).toStrictEqual([
      ["system.sockets.files.min_alloc.set", "string:", "i8:1024"],
      ["system.sockets.files.max_alloc.set", "string:", "i8:4096"],
      ["system.sockets.adjust_alloc"],
    ]);
  });
});
