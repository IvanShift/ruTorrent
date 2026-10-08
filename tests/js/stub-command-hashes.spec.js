import { readFileSync } from "fs";

window.$ = require("jquery");

// js/rtorrent.js splits a large request into several multicalls, and a
// response handler reads this.hashes as the downloads the part it answers
// covers: getalltrackers keys its result by them, plugins/extratio rechecks
// them after a label. this.hashes used to be the selection indexed by command
// number, which is right only while every download has exactly one command;
// start, stop, ban and setprio have more, and a part of one of them named
// downloads it did not touch and left out ones it did (R4-NF-4).

function load(source, prefix = "", suffix = "") {
  const script = document.createElement("script");
  script.textContent = prefix + readFileSync(source, "utf8") + suffix;
  document.body.appendChild(script);
}

function loadUI(apiVersion = 24) {
  window.theWebUI = {
    settings: { "webui.needmessage": true },
    showFlags: 0xffff,
    systemInfo: { rTorrent: { apiVersion, iVersion: 0x1018, started: true } },
  };
  for (const source of ["../lang/en.js", "../js/common.js", "../js/content.js", "../js/rtorrent.js"])
    load(source);
  correctContent();
}

const hashOf = (n) => n.toString(16).toUpperCase().padStart(40, "0");

// The first parameter of each command in the request the stub built: the
// download it acts on, with any ":tN"/":fN"/":pN" index removed.
function targets(content) {
  const found = [];
  const member = /<name>params<\/name><value><array><data><value><string>([0-9A-F]{40})/g;
  let match;
  while ((match = member.exec(content)) !== null)
    found.push(match[1]);
  return found;
}

const distinct = (list) => list.filter((hash, i) => list.indexOf(hash) === i);

// Every part of the request, with the hashes the stub hands its handler.
function parts(stub) {
  const all = [];
  for (;;) {
    all.push({ targets: targets(stub.content), hashes: stub.hashes.slice() });
    if (stub.commandOffset >= stub.commands.length)
      return all;
    stub.makeNextMultiCall();
  }
}

test.each(["start", "stop"])("each part of %s names exactly the downloads its commands act on", (action) => {
  // Before API 11 rTorrent takes 2 MB a request: 8000 downloads with two
  // commands each need more than one part.
  loadUI(10);
  const hashes = Array.from({ length: 8000 }, (_, i) => hashOf(i + 1));
  const stub = new rTorrentStub(`?action=${action}` + hashes.map((h) => "&hash=" + h).join(""));
  const all = parts(stub);
  expect(all.length).toBeGreaterThan(1);
  for (const part of all)
    expect(part.hashes).toStrictEqual(distinct(part.targets));
  expect(distinct(all.flatMap((part) => part.hashes))).toStrictEqual(hashes);
});

test("getalltrackers keeps one download per command in every part", () => {
  loadUI(10);
  const hashes = Array.from({ length: 6000 }, (_, i) => hashOf(i + 1));
  const stub = new rTorrentStub("?action=getalltrackers" + hashes.map((h) => "&hash=" + h).join(""));
  const all = parts(stub);
  expect(all.length).toBeGreaterThan(1);
  for (const part of all)
    expect(part.hashes).toStrictEqual(part.targets);
  expect(all.flatMap((part) => part.hashes)).toStrictEqual(hashes);
});

test("a part that is a single command names its download", () => {
  loadUI();
  const [a, b, c] = [hashOf(1), hashOf(2), hashOf(3)];
  const stub = new rTorrentStub(`?action=getalltrackers&hash=${a}&hash=${b}&hash=${c}`);
  expect(stub.hashes).toStrictEqual([a, b, c]);
  // The last part of a split request can be one command, sent as a plain call.
  stub.commandOffset = 2;
  stub.makeNextMultiCall();
  expect(stub.content).toContain("<methodName>t.multicall</methodName>");
  expect(stub.hashes).toStrictEqual([c]);
});

test("a request whose commands name no download keeps the selection it was given", () => {
  loadUI();
  const stub = new rTorrentStub("?action=getsettings");
  expect(stub.hashes).toStrictEqual([]);
  const files = new rTorrentStub(`?action=getfiles&hash=${hashOf(9)}`);
  expect(files.hashes).toStrictEqual([hashOf(9)]);
});

test("a request whose commands name no download is still split within the limit", () => {
  // A part ends when the next command would pass the limit, whether or not
  // its commands name a download; every part carries at least one command.
  loadUI(10);
  const stub = new rTorrentStub("?action=getsettings");
  const filler = "x".repeat(200);
  for (let i = 0; i < 12000; i++) {
    const cmd = new rXMLRPCCommand("cat");
    cmd.addParameter("string", filler + i);
    stub.commands.push(cmd);
  }
  stub.commandOffset = 0;
  stub.makeNextMultiCall();
  let sent = 0;
  let count = 0;
  for (;;) {
    const commands = (stub.content.match(/<name>methodName<\/name>/g) || []).length;
    expect(commands).toBeGreaterThan(0);
    expect(stub.content.length).toBeLessThanOrEqual(2 << 20);
    expect(stub.hashes).toStrictEqual([]);
    sent += commands;
    count++;
    if (stub.commandOffset >= stub.commands.length)
      break;
    stub.makeNextMultiCall();
  }
  expect(count).toBeGreaterThan(1);
  expect(sent).toBe(stub.commands.length);
});

test("a command on a peer or a file names the download it belongs to", () => {
  loadUI();
  const hash = hashOf(10);
  const ban = new rTorrentStub(`?action=ban&hash=${hash}&v=PEER1&v=PEER2`);
  expect(ban.content).toContain(`${hash}:pPEER1`);
  expect(ban.hashes).toStrictEqual([hash]);
  const first = new rTorrentStub(`?action=setprioritize&hash=${hash}&v=0&v=1&s=1`);
  expect(first.content).toContain(`${hash}:f0`);
  expect(first.hashes).toStrictEqual([hash]);
});
