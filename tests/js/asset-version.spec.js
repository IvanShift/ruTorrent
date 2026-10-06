const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.resolve(__dirname, '../..');

function loadContext(version) {
    const appended = [];
    const context = {
        ruTorrentAssetVersion: version,
        theWebUI: {version: '5.3.15'},
        Date: {now: () => 123456789},
        navigator: {language: 'en'},
        document: {
            cookie: '', documentElement: {setAttribute() {}},
            createElement: () => ({}), head: {appendChild: element => appended.push(element)},
        },
    };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(path.join(root, 'lang/langs.js'), 'utf8'), context);
    const plugins = fs.readFileSync(path.join(root, 'js/plugins.js'), 'utf8');
    vm.runInContext(plugins.slice(0, plugins.indexOf('function injectScript')), context);
    context.loadUILang();
    return {context, appended};
}

test('language and injected plugin URLs use the same deployment key, independent of display version', () => {
    for (const version of ['abcdef0123456789', '0123456789abcdef']) {
        const {context, appended} = loadContext(version);
        expect(appended[0].src).toBe(`./lang/en.js?v=${version}`);
        expect(context.cacheBust('plugins/example/init.js')).toBe(`plugins/example/init.js?v=${version}`);
        expect(context.cacheBust('plugins/example/style.css?theme=dark')).toBe(`plugins/example/style.css?theme=dark&v=${version}`);
    }
});

test('unstamped checkouts share one fresh language/plugin fallback key per page', () => {
    const {context, appended} = loadContext('development');
    expect(appended[0].src).toBe('./lang/en.js?v=123456789');
    expect(context.cacheBust('plugins/example/init.js')).toBe('plugins/example/init.js?v=123456789');
});

test('the WebUI imports its modules with the deployment key', async () => {
    const {context} = loadContext('abcdef0123456789');
    const imported = [];
    Object.assign(context, {
        makeContent() {}, theContextMenu: {init() {}}, theTabs: {init() {}},
        createCategoryList: () => ({}),
        $: () => ({ready: fn => fn()}),
        importModule: url => {
            imported.push(url);
            return Promise.resolve({BackgroundTask: function () {}});
        },
    });
    context.theWebUI.init = () => {};
    const source = fs.readFileSync(path.join(root, 'js/webui.js'), 'utf8');
    vm.runInContext(source.slice(source.lastIndexOf('$(document).ready(')).replace(/\bimport\(/g, 'importModule('), context);
    await Promise.resolve();
    expect(imported).toEqual([
        './backgroundtask.js?v=abcdef0123456789',
        './category-list.js?v=abcdef0123456789',
    ]);
});

test('the static panel dependency resolves to the keyed panel module below a URL prefix', () => {
    const index = new DOMParser().parseFromString(fs.readFileSync(path.join(root, 'index.html'), 'utf8'), 'text/html');
    const map = index.querySelector('script[type="importmap"]');
    expect(map).not.toBeNull();
    if (!map) return;
    const imports = JSON.parse(map.textContent).imports;
    const base = 'https://example.invalid/rutorrent/';
    const panelSpecifier = new URL('./panel.js', `${base}js/category-list.js?v=development`).href;
    const entry = Object.entries(imports).find(([specifier]) => new URL(specifier, base).href === panelSpecifier);
    expect(entry).toBeDefined();
    expect(new URL(entry[1], base).href).toBe(`${base}js/panel.js?v=development`);
});

test('RSS module and custom-element stylesheet use the same deployment key', async () => {
    const {context} = loadContext('abcdef0123456789');
    const imports = [], styles = [];
    Object.assign(context, {
        plugin: {path: 'plugins/rss/', loadMainCSS() {}, loadLang() {}},
        importModule: url => { imports.push(url); return Promise.resolve({}); },
        injectCustomElementCSS: (tag, url) => styles.push([tag, url]),
        injectCustomElementAttribute() {},
    });
    const source = fs.readFileSync(path.join(root, 'plugins/rss/init.js'), 'utf8');
    vm.runInContext(source.slice(0, source.indexOf('if(plugin.canChangeOptions())')).replace(/\bimport\(/g, 'importModule('), context);
    await Promise.resolve();
    expect(imports).toEqual(['./plugins/rss/bbcode.js?v=abcdef0123456789']);
    expect(styles).toEqual([['panel-label', 'plugins/rss/panel-label.css?v=abcdef0123456789']]);
});
