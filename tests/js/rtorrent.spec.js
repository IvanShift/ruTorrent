import { readFileSync } from "fs";
window.$ = require("jquery");
window.theWebUI = {
  settings: {
    "webui.needmessage": true,
  },

  showFlags: 0xffff,
  systemInfo: {
    rTorrent: {
      apiVersion: 10,
      iVersion: 0x908,
      started: true,
    },
  },
};

for (const src of [
  "../lang/en.js",
  "../js/common.js",
  "../js/content.js",
  "../js/rtorrent.js",
]) {
  const scriptEl = document.createElement("script");
  scriptEl.textContent = readFileSync(src, { encoding: "utf-8" });
  document.body.appendChild(scriptEl);
}
correctContent();

function h(char) {
  return Array.from({ length: 40 }, () => char).join("");
}

function loadXML(action) {
  const fileName = `xml-responses/${action}.xml`;
  const parser = new DOMParser();
  const doc = parser.parseFromString(readFileSync(fileName), "application/xml");
  if (doc.querySelector("parsererror")) {
    throw new Error(`Error parsing xml file: ${fileName}`);
  }
  return doc;
}

describe("xmlrpc calls", () => {
  function withRtorrentVersion(version, callback) {
    const oldAliases = theRequestManager.aliases;
    const oldVersion = theWebUI.systemInfo.rTorrent.iVersion;

    theRequestManager.aliases = {};
    theWebUI.systemInfo.rTorrent.iVersion = version;

    try {
      correctContent();
      callback();
    } finally {
      theRequestManager.aliases = oldAliases;
      theWebUI.systemInfo.rTorrent.iVersion = oldVersion;
    }
  }

  it("maps rTorrent 0.16 commands without deprecated aliases", () => {
    withRtorrentVersion(0x100e, () => {
      expect(theRequestManager.map("execute")).toBe("execute");
      expect(theRequestManager.map("schedule")).toBe("schedule");
      expect(theRequestManager.map("schedule_remove")).toBe("schedule.remove");
      expect(theRequestManager.map("ratio.min.set")).toBe(
        "group.seeding.ratio.min.set"
      );

      const command = new rXMLRPCCommand("schedule");
      expect(command.command).toBe("schedule");
      expect(command.params[0]).toStrictEqual({ type: "string", value: "" });

      // rtorrent 0.16's dispatcher parses the first param of any call as
      // the target, so the ratio setters must send ('', value) — prm: 1
      // makes rXMLRPCCommand prepend the empty target.
      const ratioCommand = new rXMLRPCCommand("ratio.min.set");
      ratioCommand.addParameter("i4", 100);
      expect(ratioCommand.command).toBe("group.seeding.ratio.min.set");
      expect(ratioCommand.params).toStrictEqual([
        { type: "string", value: "" },
        { type: "i4", value: 100 },
      ]);
    });
  });

  it("keeps rTorrent 0.10.2 aliases available on 0.16.0", () => {
    withRtorrentVersion(0x1000, () => {
      expect(theRequestManager.map("dht")).toBe("dht.mode.set");
      expect(theRequestManager.map("connection_leech")).toBe(
        "protocol.connection.leech.set"
      );
      expect(theRequestManager.map("ratio.min.set")).toBe(
        "group.seeding.ratio.min.set"
      );
    });
  });

  it("maps rTorrent 0.16.16 commands to canonical names", () => {
    withRtorrentVersion(0x1010, () => {
      expect(theRequestManager.map("get_http_proxy")).toBe(
        "network.proxy.http"
      );
      expect(theRequestManager.map("set_http_proxy")).toBe(
        "network.proxy.http.set"
      );
      expect(theRequestManager.map("get_proxy_address")).toBe(
        "network.proxy.global"
      );
      expect(theRequestManager.map("set_proxy_address")).toBe(
        "network.proxy.global.set"
      );
      expect(theRequestManager.map("d.multicall")).toBe("d.multicall");
      expect(theRequestManager.map("d.multicall2")).toBe("d.multicall");

      const command = new rXMLRPCCommand("d.multicall");
      expect(command.command).toBe("d.multicall");
      expect(command.params[0]).toStrictEqual({ type: "string", value: "" });
    });
  });

  it("switches port aliases at the rTorrent 0.16.18 boundary", () => {
    withRtorrentVersion(0x1011, () => {
      expect(theRequestManager.map("get_port_range")).toBe(
        "network.port_range"
      );
      expect(theRequestManager.map("set_port_range")).toBe(
        "network.port_range.set"
      );
      expect(theRequestManager.map("get_port_random")).toBe(
        "network.port_random"
      );
      expect(theRequestManager.map("set_port_random")).toBe(
        "network.port_random.set"
      );
      expect(theRequestManager.map("get_port_open")).toBe(
        "network.port_open"
      );
      expect(theRequestManager.map("set_port_open")).toBe(
        "network.port_open.set"
      );
      expect(theRequestManager.map("port_open")).toBe("network.port_open");
    });

    for (const version of [0x1012, 0x1013, 0x1015]) {
      withRtorrentVersion(version, () => {
        expect(theRequestManager.map("get_port_range")).toBe(
          "network.listen.port.range"
        );
        expect(theRequestManager.map("set_port_range")).toBe(
          "network.listen.port.range.set"
        );
        expect(theRequestManager.map("get_port_random")).toBe(
          "network.listen.port.random"
        );
        expect(theRequestManager.map("set_port_random")).toBe(
          "network.listen.port.random.set"
        );
        expect(theRequestManager.map("get_port_open")).toBe("cat");
        expect(theRequestManager.map("set_port_open")).toBe("cat");
        expect(theRequestManager.map("port_open")).toBe("cat");
        expect(theRequestManager.map("get_max_open_sockets")).toBe(
          "system.sockets.max_size"
        );
        expect(theRequestManager.map("set_max_open_files")).toBe(
          "system.sockets.files.max_alloc.set"
        );
      });
    }
  });

  it("loads rTorrent 0.10.2 aliases at the 0.10.2 boundary", () => {
    // iVersion packs one version component per byte: 0.10.2 is 0x0a02.
    // Daemons older than 0.10.2 must not get its aliases.
    for (const version of [0x908, 0x0a01]) {
      withRtorrentVersion(version, () => {
        expect(theRequestManager.map("dht")).toBe("dht");
        expect(theRequestManager.map("connection_seed")).toBe(
          "connection_seed"
        );
      });
    }

    withRtorrentVersion(0x0a02, () => {
      expect(theRequestManager.map("dht")).toBe("dht.mode.set");
      expect(theRequestManager.map("connection_seed")).toBe(
        "protocol.connection.seed.set"
      );
      expect(theRequestManager.map("ratio.min.set")).toBe(
        "group2.seeding.ratio.min.set"
      );
    });
  });

  it("routes d.is_partially_done to a no-op below rTorrent 0.9.0", () => {
    withRtorrentVersion(0x809, () => {
      expect(theRequestManager.map("d.is_partially_done=")).toBe("cat=");
    });
    withRtorrentVersion(0x900, () => {
      expect(theRequestManager.map("d.is_partially_done=")).toBe(
        "d.is_partially_done="
      );
    });
    withRtorrentVersion(0x1014, () => {
      expect(theRequestManager.map("d.is_partially_done=")).toBe(
        "d.is_partially_done="
      );
    });
    withRtorrentVersion(0x1015, () => {
      expect(theRequestManager.map("d.is_partially_done=")).toBe(
        "d.is_partially_done="
      );
    });
    // 0 is the sentinel php/getplugins.php emits while the daemon is
    // unreachable. The alias covers it on purpose: cat is understood by every
    // daemon generation, so an unknown version degrades to the no-op instead
    // of risking a faulted multicall.
    withRtorrentVersion(0, () => {
      expect(theRequestManager.map("d.is_partially_done=")).toBe("cat=");
    });
  });

  // js/content.js keeps a second, hand-written copy of the alias tables in
  // php/methods-*.php, and only a handful of its entries were asserted by
  // name above -- 23 across both halves of the suite, out of 317 here.
  // Nothing checked that a target is a command the daemon actually has, so
  // the tests below walk the whole map instead of sampling it.
  //
  // The registry to hold it against is the 982-name system.listMethods
  // answer from the live 0.16.20 daemon, which the PHP suite already carries
  // as a fixture. Read it out of that file rather than keeping a second
  // copy here, so the two halves of the suite cannot drift apart.
  function stockDaemonMethods() {
    const suite = readFileSync("php/RtorrentCompatibilityTest.php", {
      encoding: "utf-8",
    });
    const names = /<<<'LISTMETHODS'\n([\s\S]*?)\nLISTMETHODS/
      .exec(suite)[1]
      .split("\n");
    expect(names).toHaveLength(982);
    return new Set(names);
  }

  function effectiveTargets(version) {
    let aliases, targets;
    withRtorrentVersion(version, () => {
      aliases = Object.keys(theRequestManager.aliases);
      targets = new Set(aliases.map((alias) => theRequestManager.map(alias)));
    });
    return { keys: aliases.length, targets };
  }

  it("maps every alias to a name the daemon registers", () => {
    // rtorrent registers dht.throttle.name, its setter and throttle.ip only
    // inside if(method.use_deprecated == 1) (src/main.cc:388-433), and since
    // 0.16.14 that can only be turned on with the -D launch option. A stock
    // daemon answers "Method not defined" to all three. They are dormant
    // rather than broken -- nothing sends get_dht_throttle, set_dht_throttle
    // or throttle_ip -- but the set is pinned exactly, so a fourth one, or a
    // first caller, fails here instead of in a user's settings panel.
    //
    // The empty entry is content.js's own (:563): an identity mapping that
    // keeps an empty command name empty. It is not a daemon method and is
    // listed here for that reason, not as a fault.
    const unregistered = [
      "",
      "dht.throttle.name",
      "dht.throttle.name.set",
      "throttle.ip",
    ];
    const registry = stockDaemonMethods();

    // Only 0.16.18 and later are held against this registry: 0.16.16 and
    // below map the port commands to network.port_range and friends, which
    // 0.16.18 removed, so the live daemon's list is the wrong oracle there.
    for (const version of [0x1012, 0x1015]) {
      const { targets } = effectiveTargets(version);
      expect([...targets].filter((t) => !registry.has(t)).sort()).toEqual(
        unregistered
      );
    }
  });

  it("keeps the alias table the same size at every version gate", () => {
    // The walk above would assert nothing at all over an empty map, and the
    // JS tables are gated by hand in correctContent(), so pin what each gate
    // is expected to produce. A count that moves means a table was edited --
    // deliberately or by a bad merge -- and wants looking at.
    const expected = [
      [0x809, 4, 4],
      [0x908, 299, 285],
      [0x0a02, 314, 298],
      [0x1000, 314, 298],
      [0x1010, 317, 299],
      [0x1012, 317, 297],
      [0x1015, 317, 297],
    ];
    for (const [version, keys, targets] of expected) {
      const effective = effectiveTargets(version);
      const seen = [effective.keys, effective.targets.size];
      expect({ [version.toString(16)]: seen }).toEqual({
        [version.toString(16)]: [keys, targets],
      });
    }
  });

  it("should parse getprops response", () => {
    const stub = new rTorrentStub(`?action=getprops&hash=${h("A")}`);
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(ret).toStrictEqual({
      [h("A")]: {
        pex: "0",
        peers_max: "100",
        peers_min: "40",
        tracker_numwant: "-1",
        ulslots: "50",
        superseed: 0,
      },
    });
  });

  it("should parse gettotal response", () => {
    const stub = new rTorrentStub("?action=gettotal");
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(ret).toStrictEqual({ UL: 2222, DL: 1111, rateUL: 222, rateDL: 111 });
  });

  it("should parse getopen response", () => {
    const stub = new rTorrentStub("?action=getopen");
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(ret).toStrictEqual({ http: 1, sock: 22, fd: -1 });
  });

  it("should parse getsettings response", () => {
    const stub = new rTorrentStub("?action=getsettings");
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(ret.dht).toBe(1);
    expect(ret.directory).toBe("/downloads");
    expect(ret.session).toBe("/rtorrent-path/.session/");
    const ret2 = stub.getResponse(loadXML("getsettings-nodht"));
    //console.log(ret2);
    expect(ret2.dht).toBe(0);
    expect(ret2.directory).toBe("/downloads");
    expect(ret2.session).toBe("/rtorrent-path/.session/");
  });

  it("should parse getalltrackers response", () => {
    const stub = new rTorrentStub(
      `?action=getalltrackers&hash=${h("A")}&hash=${h("B")}`
    );
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(Object.keys(ret).length).toBe(2);
    expect(ret[h("A")][0].name).toBe(
      "http://sometracker.com/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/announce"
    );
    expect(ret[h("B")][0].name).toBe(
      "http://sometracker2.com/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/announce"
    );
  });

  it("should parse getfiles response", () => {
    const stub = new rTorrentStub(`?action=getfiles&hash=${h("A")}`);
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(Object.keys(ret).length).toBe(1);
    const size = 512 * 512;
    expect(ret[h("A")]).toStrictEqual([
      { name: "File 1", size, done: 0 * size, priority: "1" },
      { name: "File 2", size, done: 0.5 * size, priority: "2" },
      { name: "File 3", size, done: 1 * size, priority: "3" },
    ]);
  });

  it("should parse getpeers response", () => {
    const stub = new rTorrentStub(`?action=getpeers&hash=${h("A")}`);
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(Object.keys(ret).length).toBe(2);
    expect(ret[h("X")].ip).toBe("111.111.111.111");
    expect(ret[h("Y")].ip).toBe("222.222.222.222");
  });

  it("should parse gettrackers response", () => {
    const stub = new rTorrentStub(`?action=gettrackers&hash=${h("A")}`);
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    expect(Object.keys(ret).length).toBe(1);
    expect(ret[h("A")][0].name).toBe(
      "https://tracker.site.com/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/announce"
    );
    expect(ret[h("A")][1].name).toBe(
      "https://tracker.site2.com/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/announce"
    );
  });

  it("should parse list response", () => {
    const stub = new rTorrentStub("?list=1");
    //console.log(stub.content);
    const ret = stub.getResponse(loadXML(stub.action));
    //console.log(ret);
    for (const [hash, label, comment] of [
      [h("A"), "Cat A", ""],
      [h("B"), "Cat A/Sec 1", ""],
      [h("C"), "Cat B/Sec 1", "some comment"],
      [h("D"), "Cat C", "https://site.com/content"],
    ]) {
      expect(ret.torrents[hash].name).toBe(`Name of ${hash}`);
      expect(ret.torrents[hash].save_path).toBe("/path/to/torrent");
      expect(ret.torrents[hash].label).toBe(label);
      expect(ret.torrents[hash].comment).toBe(comment);
    }
  });

  // The server diffs against a cached copy of the previous answer. When it has
  // none -- first poll, or the cache was evicted or could not be written -- it
  // sends the whole list and no deletions, and says so with "full".
  function seedListState() {
    const stub = new rTorrentStub("?list=1");
    stub.getResponse(loadXML(stub.action));
    return stub;
  }

  it("drops torrents a full list does not mention", () => {
    const stub = seedListState();
    expect(Object.keys(theRequestManager.torrents)).toHaveLength(4);

    const kept = { [h("A")]: theRequestManager.torrents[h("A")] };
    const ret = stub.listResponse({ t: kept, cid: 7, full: 1 });

    expect(Object.keys(theRequestManager.torrents)).toEqual([h("A")]);
    expect(Object.keys(ret.torrents)).toEqual([h("A")]);
  });

  it("keeps torrents a partial list does not mention", () => {
    const stub = seedListState();
    const changed = { [h("A")]: theRequestManager.torrents[h("A")] };

    stub.listResponse({ t: changed, cid: 8 });

    expect(Object.keys(theRequestManager.torrents).sort()).toEqual(
      [h("A"), h("B"), h("C"), h("D")].sort()
    );
  });

  it("reads the partially done flag from the list response", () => {
    const stub = new rTorrentStub("?list=1");
    const ret = stub.getResponse(loadXML(stub.action));
    expect(ret.torrents[h("A")].partially_done).toBe(0);
    // B is the fixture's partial seed: incomplete (done 500) yet every
    // selected chunk on disk. Without one such torrent the flag could be
    // derived from done alone and the suite would not notice.
    expect(ret.torrents[h("B")].partially_done).toBe(1);
    expect(ret.torrents[h("C")].partially_done).toBe(0);
    expect(ret.torrents[h("D")].partially_done).toBe(1);
  });
});

describe("visibilitychange handler", () => {
  let hiddenDescriptor;

  beforeEach(() => {
    // Save original descriptor so we can restore it after each test
    hiddenDescriptor = Object.getOwnPropertyDescriptor(
      Document.prototype,
      "hidden"
    );
  });

  afterEach(() => {
    // Restore original document.hidden behavior
    delete document.hidden;
    if (hiddenDescriptor) {
      Object.defineProperty(Document.prototype, "hidden", hiddenDescriptor);
    }
    // Reset state
    theWebUI.deltaTime = 0;
    theWebUI.serverDeltaTime = 0;
  });

  it("should reset deltaTime and serverDeltaTime when tab becomes visible", () => {
    // Simulate cached deltas from a previous Ajax_UpdateTime call
    theWebUI.deltaTime = 5000;
    theWebUI.serverDeltaTime = 3000;

    // Mock document.hidden to return false (tab is now visible)
    Object.defineProperty(document, "hidden", {
      configurable: true,
      get: () => false,
    });

    document.dispatchEvent(new Event("visibilitychange"));

    expect(theWebUI.deltaTime).toBe(0);
    expect(theWebUI.serverDeltaTime).toBe(0);
  });

  it("should NOT reset deltas when tab becomes hidden", () => {
    theWebUI.deltaTime = 5000;
    theWebUI.serverDeltaTime = 3000;

    // Mock document.hidden to return true (tab is now hidden)
    Object.defineProperty(document, "hidden", {
      configurable: true,
      get: () => true,
    });

    document.dispatchEvent(new Event("visibilitychange"));

    // Values should remain unchanged
    expect(theWebUI.deltaTime).toBe(5000);
    expect(theWebUI.serverDeltaTime).toBe(3000);
  });
});
