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
