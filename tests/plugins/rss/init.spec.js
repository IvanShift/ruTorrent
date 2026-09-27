import { readFileSync } from "fs";
import { CategoryList } from "../../../js/category-list";
import * as bbcodeModule from "../../../plugins/rss/bbcode";

window.$ = require("jquery");
window.theWebUI = {
  version: "0.0.0",
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
  categoryList: new CategoryList({}),
  resizeTop: (w, h) => console.log(`resizeTop ${w}w ${h}h`),
};
window.GetActiveLanguage = function () {
  return "en";
};
window.rsstestingbbcodeModule = bbcodeModule;

document.body.append(
  ...["category-list", "panel-label"].map((elementTag) =>
    Object.assign(document.createElement("template"), {
      id: `${elementTag}-template`,
      innerHTML: '<link rel="stylesheet" />',
    })
  )
);

for (const src of [
  "../js/sanitize.js",
  "../js/sanitize.config.js",
  "../js/custom-elements.js",
  "../js/category-list-elements.js",
  "../lang/en.js",
  "../js/common.js",
  "../js/content.js",
  "../js/rtorrent.js",
  "../js/plugins.js",
  "../plugins/rss/init.js",
]) {
  const scriptEl = document.createElement("script");
  let code = readFileSync(src, { encoding: "utf-8" });
  if (src.endsWith("rss/init.js")) {
    window.theWebUI.categoryList.selection = { ids: () => [] };
    // Dynamic imports and <script type="module"> are not supported by jsdom
    // (See https://github.com/jsdom/jsdom/issues/2475)
    // Workaround: initialize module by replacing code
    code = code.replace(
      "var bbcode = null;",
      "var bbcode = window.rsstestingbbcodeModule;"
    );
    code = `(function () { var plugin = new rPlugin('rss', 4.0, 'a', 'b', 'c', 'd'); plugin.path="../plugins/rss/"; ${code}; })();`;
  }
  scriptEl.setAttribute("type", "text/javascript");
  scriptEl.textContent = code;
  document.head.appendChild(scriptEl);
}
correctContent();
document.body.appendChild($("<div>").attr("id", "rsslayout")[0]);

describe("rss details", () => {
  beforeEach(() => {
    $("#rsslayout").text("");
  });

  it("should sanitize html code", () => {
    theWebUI.rssItems = { rssid: "rsslink" };
    const stub = new rTorrentStub("?action=getrssdetails&s=rssid");
    stub.getrssdetailsResponse(
      '[b][color=#556677]<ins>Important</ins> :smile:[/color]<img src="https://linkto.img" onerror=alert("hax2")/><script>alert(\'hax\');</script>[/b]'
    );
    // Sanitize.RESTRICTED does not allow img
    expect($("#rsslayout div").html()).toEqual(
      '<b><span class="bbcode-color" style="color: #556677"><ins>Important</ins> 🙂</span>alert(\'hax\');</b>'
    );
    stub.getrssdetailsResponse(
      '[style color=0000FF font="times" size=18 wild=attr align=center]Text[/style]'
    );
    expect($("#rsslayout div").html()).toEqual(
      '<span class="bbcode-color bbcode-font-times bbcode-size bbcode-align-center" style="color: #0000FF; font-size: 18px">Text</span>'
    );
    stub.getrssdetailsResponse("[style color=bad font=caps]Text[/style]");
    expect($("#rsslayout div").html()).toEqual(
      '<span class="bbcode-font-caps">Text</span>'
    );
  });
});

describe("rss error messages", () => {
  let previousNoty;
  let previousFetchMessage;

  beforeEach(() => {
    previousNoty = window.noty;
    previousFetchMessage = theUILang.cantFetchRSS;
    theUILang.cantFetchRSS = "Error loading feed.";
    theUILang.rssDontExist = "The feed doesn't exist.";
    window.noty = jest.fn();
    theWebUI.rssShowErrorsDelayed = false;
    theWebUI.rssLabels = {};
    delete window.__rssErrorEval;
  });

  afterEach(() => {
    window.noty = previousNoty;
    theUILang.cantFetchRSS = previousFetchMessage;
    delete theUILang.rssDontExist;
    delete window.__rssErrorEval;
  });

  it("shows a saved legacy error without executing its description", () => {
    const status = "500'+(window.__rssErrorEval='RAN')+'";
    const description = "theUILang.cantFetchRSS + ' - [RSS-HTTP-Error] Status: " +
      status + "'";
    theWebUI.showErrors([{ time: 0, desc: description, prm: "" }]);
    expect(window.__rssErrorEval).toBeUndefined();
    expect(window.noty).toHaveBeenCalledWith(
      expect.stringContaining(theUILang.cantFetchRSS + " - [RSS-HTTP-Error] Status: " + status),
      "error", true
    );
  });

  it("localizes a known saved legacy key without evaluating unknown text", () => {
    expect(theWebUI.rssErrorText({ desc: "theUILang.rssDontExist" }))
      .toBe(theUILang.rssDontExist);
    expect(theWebUI.rssErrorText({ desc: "theUILang.unknown + window.__rssErrorEval=1" }))
      .toBe("theUILang.unknown + window.__rssErrorEval=1");
    expect(window.__rssErrorEval).toBeUndefined();
  });

  it("localizes a feed error and keeps the external detail as text", () => {
    const detail = "[RSS-HTTP-Error] Status: 500'+(window.__rssErrorEval='RAN')+'";
    theWebUI.showErrors([{
      time: 0, key: "cantFetchRSS", detail, prm: "https://feed.example/rss"
    }]);
    expect(window.__rssErrorEval).toBeUndefined();
    expect(window.noty).toHaveBeenCalledWith(
      expect.stringContaining(theUILang.cantFetchRSS + " - " + detail), "error", true
    );
  });
});

describe("RSS refresh transport", () => {
  it.each(["rssrefresh", "rssgrouprefresh"])("sends %s by POST", action => {
    const stub = new rTorrentStub(`?action=${action}`);
    expect(stub.mountPoint).toBe("plugins/rss/action.php");
    expect(stub.method).toBe("POST");
    expect(stub.cache).toBe(false);
  });
});
