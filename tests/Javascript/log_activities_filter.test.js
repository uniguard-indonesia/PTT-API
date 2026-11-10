const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const bladePath = path.resolve(__dirname, '../../resources/views/pages/admin/log-activities.blade.php');
const bladeSource = fs.readFileSync(bladePath, 'utf8');

function extractInlineScript(source) {
    const scripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)];
    assert.ok(scripts.length > 0, 'Expected inline <script> block in log-activities.blade.php');
    return scripts[scripts.length - 1][1]
        .replace(/\{\{\s*route\(['"]admin\.log-activity\.index['"]\)\s*\}\}/g, '/admin/log-activity')
        .replace(/\{\{\s*asset\(['"]:attachment['"]\)\s*\}\}/g, '/attachments/:attachment');
}

test('Log Activities Blade HTML controls and inline DataTables JS contracts', async (t) => {
    await t.test('HTML filter controls have explicit button types and date input ids/names', () => {
        assert.match(bladeSource, /<input[^>]*type=["']date["'][^>]*id=["']StartDate["'][^>]*name=["']start_date["']/);
        assert.match(bladeSource, /<input[^>]*type=["']date["'][^>]*id=["']EndDate["'][^>]*name=["']end_date["']/);
        assert.match(bladeSource, /<button[^>]*type=["']button["'][^>]*id=["']btnFilter["']/);
        assert.match(bladeSource, /<button[^>]*type=["']button["'][^>]*id=["']btnClear["']/);
    });

    await t.test('Inline script wires default order, start_date/end_date ajax payload, #btnFilter and #btnClear', () => {
        let capturedDtConfig = null;
        let reloadCalls = [];
        let readyCallback = null;

        const elements = {
            '#Company': createMockElement(''),
            '#StartDate': createMockElement(''),
            '#EndDate': createMockElement(''),
            '#btnFilter': createMockElement(''),
            '#btnClear': createMockElement(''),
            'input[type="date"]': createMockElement(''),
            '#daTable': {
                DataTable(config) {
                    capturedDtConfig = config;
                    return {
                        ajax: {
                            reload(cb, resetPaging) {
                                reloadCalls.push({ cb: cb ?? null, resetPaging });
                            }
                        }
                    };
                }
            }
        };

        function createMockElement(initialVal) {
            return {
                value: initialVal,
                listeners: {},
                val(v) {
                    if (v !== undefined) {
                        this.value = v;
                        return this;
                    }
                    return this.value;
                },
                on(evt, handler) {
                    this.listeners[evt] = handler;
                    return this;
                },
                trigger(evt) {
                    if (this.listeners[evt]) {
                        this.listeners[evt]();
                    }
                }
            };
        }

        const documentToken = { __isDocument: true };
        const $ = (selector) => {
            if (selector === documentToken) {
                return {
                    ready(fn) {
                        readyCallback = fn;
                    }
                };
            }
            if (typeof selector === 'string' && selector.startsWith('meta[')) {
                return { attr: () => 'test-csrf-token' };
            }
            return elements[selector] || createMockElement('');
        };
        $.ajaxSetup = () => {};

        const context = vm.createContext({
            $,
            document: documentToken
        });

        const scriptCode = extractInlineScript(bladeSource);
        vm.runInContext(scriptCode, context);

        assert.ok(capturedDtConfig, 'DataTable must be initialized');
        assert.deepEqual(
            Array.from(capturedDtConfig.order || [], (row) => Array.from(row)),
            [[0, 'desc']],
            'DataTables must configure default order [[0, "desc"]]'
        );

        // Execute document.ready bindings
        assert.strictEqual(typeof readyCallback, 'function', '$(document).ready callback must be registered');
        readyCallback();

        // Bind company_id, start_date, end_date in ajax.data
        elements['#Company'].val('5');
        elements['#StartDate'].val('2026-11-01');
        elements['#EndDate'].val('2026-11-06');
        const reqData = {};
        capturedDtConfig.ajax.data(reqData);
        assert.equal(reqData.company_id, '5');
        assert.equal(reqData.start_date, '2026-11-01');
        assert.equal(reqData.end_date, '2026-11-06');

        // #Company change reloads table
        reloadCalls = [];
        elements['#Company'].trigger('change');
        assert.equal(reloadCalls.length, 1);

        // #btnFilter click reloads table
        reloadCalls = [];
        elements['#btnFilter'].trigger('click');
        assert.equal(reloadCalls.length, 1);

        // #btnClear resets #Company, #StartDate, #EndDate and reloads table
        reloadCalls = [];
        elements['#Company'].val('9');
        elements['#StartDate'].val('2026-11-02');
        elements['#EndDate'].val('2026-11-05');
        elements['#btnClear'].trigger('click');

        assert.equal(elements['#Company'].val(), '');
        assert.equal(elements['#StartDate'].val(), '');
        assert.equal(elements['#EndDate'].val(), '');
        const clearedReq = {};
        capturedDtConfig.ajax.data(clearedReq);
        assert.equal(clearedReq.company_id, '');
        assert.equal(clearedReq.start_date, '');
        assert.equal(clearedReq.end_date, '');
        assert.equal(reloadCalls.length, 1);
    });
});

function escapeAttrForMock(val) {
    return String(val)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function escapeTextForMock(val) {
    return String(val)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function createDomMock() {
    function makeNode(tagName) {
        const attrs = {};
        const children = [];
        return {
            nodeType: 1,
            tagName: tagName.toUpperCase(),
            attrs,
            children,
            set type(v) { attrs.type = v; },
            get type() { return attrs.type; },
            set className(v) { attrs['class'] = v; },
            get className() { return attrs['class'] || ''; },
            set controls(v) { attrs.controls = v ? '' : undefined; },
            set src(v) { attrs.src = v; },
            setAttribute(k, v) { attrs[k] = String(v); },
            getAttribute(k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
            appendChild(child) {
                children.push(child);
                return child;
            },
            get textContent() {
                return children.map((c) => c.textContent || '').join('');
            },
            get innerHTML() {
                return children.map((c) => c.nodeType === 3 ? escapeTextForMock(c.textContent) : c.outerHTML).join('');
            },
            get outerHTML() {
                const tag = tagName.toLowerCase();
                const attrStr = Object.entries(attrs)
                    .filter(([, v]) => v !== undefined)
                    .map(([k, v]) => ` ${k}="${escapeAttrForMock(v)}"`)
                    .join('');
                return `<${tag}${attrStr}>${this.innerHTML}</${tag}>`;
            }
        };
    }

    return {
        createElement(tag) {
            return makeNode(tag);
        },
        createTextNode(text) {
            return { nodeType: 3, textContent: String(text) };
        }
    };
}

function createLocationMapHarness({ withDocument = true } = {}) {
    let capturedDtConfig = null;
    let readyCallback = null;
    let mapInstances = 0;
    const tileLayers = [];
    const mapCalls = {
        invalidateSize: 0,
        setView: [],
        closePopup: 0,
        initialOptions: null
    };
    const pinLayerState = {
        layers: [],
        removed: [],
        clearedCount: 0
    };
    const modalCalls = [];
    const createdMarkers = [];

    function parseEventSpec(evtSpec) {
        const dotIdx = evtSpec.indexOf('.');
        return dotIdx === -1
            ? { eventName: evtSpec, namespace: '' }
            : { eventName: evtSpec.slice(0, dotIdx), namespace: evtSpec.slice(dotIdx + 1) };
    }

    function makeInteractiveEl(initialVal = '') {
        return {
            value: initialVal,
            textValue: '',
            listeners: {},
            delegated: [],
            val(v) {
                if (v !== undefined) {
                    this.value = v;
                    return this;
                }
                return this.value;
            },
            text(v) {
                if (v !== undefined) {
                    this.textValue = String(v);
                    return this;
                }
                return this.textValue;
            },
            on(evt, selectorOrHandler, maybeHandler) {
                if (typeof selectorOrHandler === 'string' && typeof maybeHandler === 'function') {
                    this.delegated.push({ evt, selector: selectorOrHandler, handler: maybeHandler });
                } else if (typeof selectorOrHandler === 'function') {
                    const { eventName, namespace } = parseEventSpec(evt);
                    this.listeners[eventName] = this.listeners[eventName] || [];
                    this.listeners[eventName].push({ fn: selectorOrHandler, namespace, once: false });
                }
                return this;
            },
            one(evt, handler) {
                if (typeof handler === 'function') {
                    const { eventName, namespace } = parseEventSpec(evt);
                    this.listeners[eventName] = this.listeners[eventName] || [];
                    this.listeners[eventName].push({ fn: handler, namespace, once: true });
                }
                return this;
            },
            off(evt) {
                const { eventName, namespace } = parseEventSpec(evt);
                if (!this.listeners[eventName]) {
                    return this;
                }
                if (namespace) {
                    this.listeners[eventName] = this.listeners[eventName].filter((l) => l.namespace !== namespace);
                } else {
                    this.listeners[eventName] = [];
                }
                return this;
            },
            trigger(evt) {
                const { eventName } = parseEventSpec(evt);
                const list = (this.listeners[eventName] || []).slice();
                this.listeners[eventName] = (this.listeners[eventName] || []).filter((l) => !l.once);
                list.forEach((entry) => entry.fn());
                return this;
            },
            modal(action) {
                modalCalls.push(action);
                if (action === 'show') {
                    this.trigger('shown.bs.modal');
                } else if (action === 'hide') {
                    this.trigger('hidden.bs.modal');
                }
                return this;
            }
        };
    }

    const daTableEl = makeInteractiveEl('');
    daTableEl.DataTable = function (config) {
        capturedDtConfig = config;
        return { ajax: { reload() {} } };
    };
    daTableEl.clickDelegated = function (selector, targetEl) {
        this.delegated
            .filter((d) => d.evt === 'click' && d.selector === selector)
            .forEach((d) => d.handler.call(targetEl, { preventDefault() {} }));
    };

    const elements = {
        '#Company': makeInteractiveEl(''),
        '#StartDate': makeInteractiveEl(''),
        '#EndDate': makeInteractiveEl(''),
        '#btnFilter': makeInteractiveEl(''),
        '#btnClear': makeInteractiveEl(''),
        'input[type="date"]': makeInteractiveEl(''),
        '#daTable': daTableEl,
        '#locationMapModal': makeInteractiveEl(''),
        '#locationMapMeta': makeInteractiveEl('')
    };

    const documentObj = withDocument ? createDomMock() : { __isDocument: true };

    const $ = (selector) => {
        if (selector === documentObj) {
            return {
                ready(fn) {
                    readyCallback = fn;
                }
            };
        }
        if (typeof selector === 'string' && selector.startsWith('meta[')) {
            return { attr: () => 'test-csrf-token' };
        }
        if (typeof selector === 'object' && selector !== null) {
            return {
                attr(name) {
                    return selector.getAttribute ? selector.getAttribute(name) : null;
                }
            };
        }
        return elements[selector] || makeInteractiveEl('');
    };
    $.ajaxSetup = () => {};

    const mockMap = {
        invalidateSize() {
            mapCalls.invalidateSize++;
        },
        setView(center, zoom, options) {
            mapCalls.setView.push({ center, zoom, options: options || null });
        },
        closePopup() {
            mapCalls.closePopup++;
        },
        hasLayer(layer) {
            return layer === mockLayerGroup;
        }
    };

    const mockLayerGroup = {
        addTo() {
            return this;
        },
        addLayer(layer) {
            pinLayerState.layers.push(layer);
        },
        removeLayer(layer) {
            pinLayerState.removed.push(layer);
            pinLayerState.layers = pinLayerState.layers.filter((l) => l !== layer);
        },
        clearLayers() {
            pinLayerState.clearedCount++;
            pinLayerState.layers = [];
        }
    };

    const L = {
        tileLayer(tileUrl, opts) {
            const layer = { url: tileUrl, opts };
            tileLayers.push(layer);
            return layer;
        },
        map(containerId, options) {
            mapInstances++;
            mapCalls.initialOptions = { containerId, options };
            return mockMap;
        },
        control: {
            layers() {
                return { addTo() { return {}; } };
            }
        },
        LayerGroup: function () {
            return mockLayerGroup;
        },
        marker(latLng) {
            const marker = {
                latLng,
                popupContent: null,
                popupOpen: false,
                popupClosedCount: 0,
                bindPopup(content) {
                    this.popupContent = content;
                    return this;
                },
                openPopup() {
                    this.popupOpen = true;
                    return this;
                },
                closePopup() {
                    this.popupOpen = false;
                    this.popupClosedCount++;
                    return this;
                }
            };
            createdMarkers.push(marker);
            return marker;
        }
    };

    const context = vm.createContext({
        $,
        L,
        document: documentObj,
        Number,
        String,
        Object,
        Array
    });

    const scriptCode = extractInlineScript(bladeSource);
    vm.runInContext(scriptCode, context);
    readyCallback();

    return {
        context,
        capturedDtConfig,
        elements,
        tileLayers,
        mapCalls,
        pinLayerState,
        modalCalls,
        createdMarkers,
        getMapInstances: () => mapInstances
    };
}

test('Log Activities location rendering, XSS safety, and in-app Leaflet temporary pin modal', async (t) => {
    await t.test('Blade template includes local Leaflet assets, Bootstrap 4 modal, and HTTPS hl=en tiles', () => {
        assert.match(bladeSource, /assets\/plugins\/leaflet\/leaflet\.css/);
        assert.match(bladeSource, /assets\/plugins\/leaflet\/leaflet\.js/);
        assert.match(bladeSource, /id=["']locationMapModal["']/);
        assert.match(bladeSource, /data-dismiss=["']modal["']/);
        assert.match(bladeSource, /id=["']activityLocationMap["']/);
        assert.doesNotMatch(bladeSource, /maps\.google\.com/);
    });

    await t.test('Activity column renders clean text for exports/sorting and clickable button only for valid coordinates', () => {
        const h = createLocationMapHarness({ withDocument: true });
        const activityCol = h.capturedDtConfig.columns[3];
        assert.equal(activityCol.data, 'activity');

        // Export buttons (Copy, Excel, Pdf, Print) configure orthogonal: 'export' and custom full-export actions
        const exportButtons = h.capturedDtConfig.buttons
            .filter((b) => ['copy', 'excel', 'pdf', 'print'].includes(b.extend) && !/Refresh/i.test(String(b.text || '')));
        assert.equal(exportButtons.length, 4);
        exportButtons.forEach((btn) => {
            assert.equal(btn.exportOptions.orthogonal, 'export');
            assert.equal(typeof btn.action, 'function');
        });

        // Structured valid coords
        const structuredRow = {
            id: 10,
            name: 'Alice',
            created_at: '10 Nov 2026 11:00 WIB',
            activity: 'Updating Location',
            latitude: -6.2088,
            longitude: 106.8456
        };
        const displayHtml = activityCol.render(structuredRow.activity, 'display', structuredRow);
        assert.match(displayHtml, /<button[^>]*class="[^"]*js-view-location/);
        assert.match(displayHtml, />(?:<i[^>]*><\/i>)?Updating Location<\/button>/);
        assert.doesNotMatch(displayHtml, /\(-6\.2088/);

        ['sort', 'filter', 'type', 'export'].forEach((dtType) => {
            assert.equal(activityCol.render(structuredRow.activity, dtType, structuredRow), 'Updating Location');
        });

        // Legacy historical raw string fallback (without latitude/longitude fields)
        const legacyRow = {
            id: 11,
            name: 'Bob',
            created_at: '10 Nov 2026 11:05 AWST',
            activity: 'Updating Location (-31.950500, 115.860500)'
        };
        const legacyDisplay = activityCol.render(legacyRow.activity, 'display', legacyRow);
        assert.match(legacyDisplay, /js-view-location/);
        assert.match(legacyDisplay, /data-lat="-31\.9505"/);
        assert.match(legacyDisplay, /data-lng="115\.8605"/);
        assert.doesNotMatch(legacyDisplay, /\(-31\.950500/);
        assert.equal(activityCol.render(legacyRow.activity, 'export', legacyRow), 'Updating Location');
        assert.equal(activityCol.render(legacyRow.activity, 'sort', legacyRow), 'Updating Location');

        // Zero & boundary coordinates must be accepted as valid
        const zeroRow = { activity: 'Updating Location', latitude: 0, longitude: 0, name: 'Zero', created_at: 'Now' };
        assert.match(activityCol.render(zeroRow.activity, 'display', zeroRow), /js-view-location/);
        const boundaryRow = { activity: 'Updating Location (-90, 180)', name: 'Edge', created_at: 'Now' };
        assert.match(activityCol.render(boundaryRow.activity, 'display', boundaryRow), /js-view-location/);

        // Coordinate-less "Updating Location" renders plain non-clickable text
        const plainRow = { activity: 'Updating Location', latitude: null, longitude: null };
        const plainHtml = activityCol.render(plainRow.activity, 'display', plainRow);
        assert.equal(plainHtml, 'Updating Location');
        assert.doesNotMatch(plainHtml, /<button/);

        // Invalid / out-of-range / NaN / Infinity / boolean / empty / mismatched explicit override coordinates render non-clickable plain text
        const invalidCases = [
            { activity: 'Updating Location (90.0001, 106.8456)' },
            { activity: 'Updating Location (-91, 0)' },
            { activity: 'Updating Location (0, 180.0001)' },
            { activity: 'Updating Location (0, -181)' },
            { activity: 'Updating Location (NaN, 100)' },
            { activity: 'Updating Location (Infinity, 100)' },
            { activity: 'Updating Location', latitude: '', longitude: '' },
            { activity: 'Updating Location', latitude: false, longitude: true },
            { activity: 'Updating Location', latitude: 95, longitude: 106.8 },
            { activity: 'Updating Location (-6.2088, 106.8456)', latitude: 95, longitude: 106.8456 }
        ];
        invalidCases.forEach((row) => {
            const rendered = activityCol.render(row.activity, 'display', row);
            assert.doesNotMatch(rendered, /<button/, `Expected non-clickable text for ${JSON.stringify(row)}`);
            assert.doesNotMatch(rendered, /js-view-location/);
        });
    });

    await t.test('Yajra pre-escaped entities are decoded once (never double-escaped) while Print exports remain XSS-safe', () => {
        const h = createLocationMapHarness({ withDocument: true });
        const cols = h.capturedDtConfig.columns;

        // Yajra DataTables applies e() (ENT_QUOTES, double_encode=true) on string fields
        const yajraLocationRow = {
            created_at: '10 Nov 2026 10:00 WIB',
            company: 'O&#039;Reilly &amp; Sons &lt;West&gt;',
            name: 'Alice &quot;O&#039;Connor&quot; &lt;Team&gt;',
            activity: 'Updating Location',
            latitude: -6.2088,
            longitude: 106.8456
        };

        assert.equal(cols[1].render(yajraLocationRow.company, 'display', yajraLocationRow), 'O&#39;Reilly &amp; Sons &lt;West&gt;');
        assert.equal(cols[2].render(yajraLocationRow.name, 'display', yajraLocationRow), 'Alice &quot;O&#39;Connor&quot; &lt;Team&gt;');
        assert.equal(cols[1].render(yajraLocationRow.company, 'sort', yajraLocationRow), "O'Reilly & Sons <West>");
        assert.equal(cols[1].render(yajraLocationRow.company, 'export', yajraLocationRow), 'O&#39;Reilly &amp; Sons &lt;West&gt;');

        // Single-pass decoding preserves literal entities stored in DB without double-decoding
        const yajraLiteralEntityRow = {
            created_at: '10 Nov 2026 10:02 WIB',
            company: 'Bob &amp;lt;b&amp;gt; Co',
            name: 'Bob &amp;lt;i&amp;gt;',
            activity: 'Logged &amp;lt;test&amp;gt;'
        };
        assert.equal(cols[1].render(yajraLiteralEntityRow.company, 'display', yajraLiteralEntityRow), 'Bob &amp;lt;b&amp;gt; Co');
        assert.equal(cols[3].render(yajraLiteralEntityRow.activity, 'display', yajraLiteralEntityRow), 'Logged &amp;lt;test&amp;gt;');

        // Unclosed HTML tag XSS in Yajra activity stays entity-encoded for Print ('export') and display
        const yajraNonLocRow = {
            activity: 'User&#039;s Login &amp; Check &lt;img src=x onerror=alert(9)&gt;'
        };
        assert.equal(
            cols[3].render(yajraNonLocRow.activity, 'display', yajraNonLocRow),
            'User&#39;s Login &amp; Check &lt;img src=x onerror=alert(9)&gt;'
        );
        assert.equal(
            cols[3].render(yajraNonLocRow.activity, 'export', yajraNonLocRow),
            'User&#39;s Login &amp; Check &lt;img src=x onerror=alert(9)&gt;'
        );
        assert.equal(
            cols[3].render(yajraNonLocRow.activity, 'sort', yajraNonLocRow),
            "User's Login & Check <img src=x onerror=alert(9)>"
        );

        // Button data attributes store single-decoded text so modal meta & Leaflet popup do not display literal entities
        const buttonHtml = cols[3].render(yajraLocationRow.activity, 'display', yajraLocationRow);
        assert.match(buttonHtml, /data-user="Alice &quot;O'Connor&quot; &lt;Team&gt;"/);
        assert.doesNotMatch(buttonHtml, /&amp;quot;|&amp;#039;/);

        // Simulate clicking rendered button (attributes decoded once by HTML parser)
        const clickedButton = {
            getAttribute(attr) {
                if (attr === 'data-lat') return '-6.2088';
                if (attr === 'data-lng') return '106.8456';
                if (attr === 'data-user') return 'Alice "O\'Connor" <Team>';
                if (attr === 'data-timestamp') return '10 Nov 2026 10:00 WIB';
                if (attr === 'data-label') return 'Updating Location';
                return null;
            }
        };
        h.elements['#daTable'].clickDelegated('.js-view-location', clickedButton);
        assert.equal(
            h.elements['#locationMapMeta'].textValue,
            'User: Alice "O\'Connor" <Team> | Time: 10 Nov 2026 10:00 WIB | Coordinates: -6.208800, 106.845600'
        );
        assert.equal(
            h.createdMarkers[0].popupContent.textContent,
            'Updating LocationAlice "O\'Connor" <Team>10 Nov 2026 10:00 WIB'
        );
    });

    await t.test('XSS payloads in user name, timestamp, and activity are safely escaped in cell and popup', () => {
        const maliciousName = 'Eve " <script>alert(1)</script> & Co';
        const maliciousTime = '<img src=x onerror=alert(2)>';

        // 1. DOM branch (document.createElement)
        const hDom = createLocationMapHarness({ withDocument: true });
        const colDom = hDom.capturedDtConfig.columns[3];
        const rowXss = {
            activity: 'Updating Location',
            latitude: -6.2088,
            longitude: 106.8456,
            name: maliciousName,
            created_at: maliciousTime
        };
        const cellHtml = colDom.render(rowXss.activity, 'display', rowXss);
        assert.doesNotMatch(cellHtml, /data-user="[^"]*<script>alert\(1\)<\/script>[^"]*"[^"]*>/);
        assert.match(cellHtml, /&quot;/);

        const nonLocXss = colDom.render('<script>alert("xss")</script>', 'display', { activity: '<script>alert("xss")</script>' });
        assert.equal(nonLocXss, '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');

        // Popup uses safe DOM text nodes (no raw <script> or <img> elements created)
        hDom.context.openLocationMapModal(rowXss);
        assert.equal(hDom.createdMarkers.length, 1);
        const popupNode = hDom.createdMarkers[0].popupContent;
        assert.equal(popupNode.tagName, 'DIV');
        assert.ok(popupNode.children.every((c) => ['STRONG', 'BR'].includes(c.tagName) || c.nodeType === 3));
        assert.ok(popupNode.textContent.includes(maliciousName));
        assert.ok(popupNode.textContent.includes(maliciousTime));
        assert.equal(hDom.elements['#locationMapMeta'].textValue.includes(maliciousName), true);

        // 2. String fallback branch (without document.createElement)
        const hStr = createLocationMapHarness({ withDocument: false });
        const colStr = hStr.capturedDtConfig.columns[3];
        const fallbackHtml = colStr.render(rowXss.activity, 'display', rowXss);
        assert.doesNotMatch(fallbackHtml, /<script>/);
        assert.doesNotMatch(fallbackHtml, /<img/);
        assert.match(fallbackHtml, /&lt;script&gt;/);
        assert.match(fallbackHtml, /&lt;img/);
    });

    await t.test('Clicking rows opens modal, calls invalidateSize(), replaces single temporary pin, and clears on close', () => {
        const h = createLocationMapHarness({ withDocument: true });

        // Simulate clicking row 1 button
        const buttonRow1 = {
            getAttribute(attr) {
                const map = {
                    'data-lat': '-6.2088',
                    'data-lng': '106.8456',
                    'data-user': 'User One',
                    'data-timestamp': '10 Nov 2026 10:00 WIB',
                    'data-label': 'Updating Location'
                };
                return map[attr] ?? null;
            }
        };

        h.elements['#daTable'].clickDelegated('.js-view-location', buttonRow1);

        assert.deepEqual(h.modalCalls, ['show']);
        assert.equal(h.getMapInstances(), 1);
        assert.equal(h.mapCalls.invalidateSize, 1);
        assert.equal(h.pinLayerState.layers.length, 1);
        assert.deepEqual(Array.from(h.pinLayerState.layers[0].latLng), [-6.2088, 106.8456]);
        assert.equal(h.pinLayerState.layers[0].popupOpen, true);
        assert.deepEqual(
            { center: Array.from(h.mapCalls.setView[0].center), zoom: h.mapCalls.setView[0].zoom, animate: h.mapCalls.setView[0].options?.animate },
            { center: [-6.2088, 106.8456], zoom: 16, animate: false }
        );

        // Verify HTTPS tile layers with hl=en match dashboard.blade.php
        const googleUrls = h.tileLayers.map((l) => l.url).filter((u) => u.includes('google.com/vt'));
        assert.equal(googleUrls.length, 3);
        googleUrls.forEach((u) => {
            assert.match(u, /^https:\/\//);
            assert.match(u, /[?&]hl=en(?:&|$)/);
        });
        assert.ok(h.tileLayers.some((l) => l.url.startsWith('https://{s}.tile.openstreetmap.org/')));

        // Modal closes -> temporary pin and open popup must be cleared immediately on hide
        const firstMarker = h.createdMarkers[0];
        h.elements['#locationMapModal'].trigger('hide.bs.modal');
        assert.equal(h.pinLayerState.layers.length, 0);
        assert.equal(firstMarker.popupClosedCount, 1);
        assert.ok(h.mapCalls.closePopup >= 1);
        h.elements['#locationMapModal'].trigger('hidden.bs.modal');
        assert.equal(h.pinLayerState.layers.length, 0);
        assert.equal(h.elements['#locationMapMeta'].textValue, '');

        // Simulate clicking row 2 button -> map instance reused, invalidateSize called again, ONLY 1 pin exists, animate: false
        const buttonRow2 = {
            getAttribute(attr) {
                const map = {
                    'data-lat': '-31.9505',
                    'data-lng': '115.8605',
                    'data-user': 'User Two',
                    'data-timestamp': '10 Nov 2026 11:00 AWST',
                    'data-label': 'Updating Location'
                };
                return map[attr] ?? null;
            }
        };
        h.elements['#daTable'].clickDelegated('.js-view-location', buttonRow2);

        assert.equal(h.getMapInstances(), 1, 'Map instance must be reused without re-initializing');
        assert.equal(h.mapCalls.invalidateSize, 2, 'invalidateSize must be called on each modal show');
        assert.equal(h.pinLayerState.layers.length, 1, 'Previous temporary pin must be replaced, never accumulated');
        assert.deepEqual(Array.from(h.pinLayerState.layers[0].latLng), [-31.9505, 115.8605]);
        assert.equal(h.mapCalls.setView[1].options?.animate, false);

        // Switching row while modal is already open replaces the single pin immediately without animated pan cancellation
        h.context.openLocationMapModal({
            latitude: 0,
            longitude: 0,
            name: 'User Three',
            created_at: '10 Nov 2026 12:00 UTC',
            label: 'Updating Location'
        });
        assert.equal(h.pinLayerState.layers.length, 1);
        assert.deepEqual(Array.from(h.pinLayerState.layers[0].latLng), [0, 0]);
        assert.equal(h.mapCalls.setView[2].options?.animate, false);

        // Clicking a row mid fade-out transition (between hide.bs.modal and hidden.bs.modal) queues clean reopen
        const callsBeforeClosingClick = h.modalCalls.length;
        h.elements['#locationMapModal'].trigger('hide.bs.modal');
        h.context.openLocationMapModal({
            latitude: -7.2575,
            longitude: 112.7521,
            name: 'User Mid-Close',
            created_at: '10 Nov 2026 13:00 WIB',
            label: 'Updating Location'
        });
        assert.equal(h.modalCalls.length, callsBeforeClosingClick, 'Must wait for hidden.bs.modal before re-showing');
        h.elements['#locationMapModal'].trigger('hidden.bs.modal');
        assert.equal(h.modalCalls.length, callsBeforeClosingClick + 1);
        assert.equal(h.modalCalls[h.modalCalls.length - 1], 'show');
        assert.equal(h.pinLayerState.layers.length, 1);
        assert.deepEqual(Array.from(h.pinLayerState.layers[0].latLng), [-7.2575, 112.7521]);

        // Invalid coordinates must be rejected and not open modal or add any pin
        const callsBeforeInvalid = h.modalCalls.length;
        const resultInvalid = h.context.openLocationMapModal({
            latitude: 95,
            longitude: 200,
            name: 'Bad Coords',
            created_at: 'Now'
        });
        assert.equal(resultInvalid, false);
        assert.equal(h.modalCalls.length, callsBeforeInvalid);
        assert.equal(h.pinLayerState.layers.length, 1);
    });
});

function createFilterAndExportHarness({ withToastr = true, initialPage = 2, initialPageLength = 10, initialSearch = 'Login', initialOrder = [[3, 'asc']], hiddenColumns = [] } = {}) {
    let capturedDtConfig = null;
    let readyCallback = null;
    let reloadCalls = [];
    let searchCalls = [];
    let currentSearch = initialSearch;
    let currentPage = initialPage;
    let currentPageLength = initialPageLength;
    let ajaxRequests = [];
    let nativeExportCalls = [];
    let buttonEnableCalls = [];
    let toastrCalls = { info: [], warning: [], error: [] };
    let alertCalls = [];
    let audioElementsCreated = 0;
    const headerLabels = ['CREATED AT', 'COMPANY', 'NAME', 'ACTIVITY', 'ATTACHMENT'];

    function makeInteractiveEl(initialVal = '') {
        return {
            value: initialVal,
            listeners: {},
            val(v) {
                if (v !== undefined) {
                    this.value = v;
                    return this;
                }
                return this.value;
            },
            on(evt, selectorOrHandler, maybeHandler) {
                const handler = typeof selectorOrHandler === 'function' ? selectorOrHandler : maybeHandler;
                if (typeof handler === 'function') {
                    this.listeners[evt] = this.listeners[evt] || [];
                    this.listeners[evt].push(handler);
                }
                return this;
            },
            trigger(evt, eventObj) {
                const list = this.listeners[evt] || [];
                list.forEach((fn) => fn.call(this, eventObj));
                return this;
            }
        };
    }

    let exportButtonsDisabled = false;
    const mockTableNode = { className: 'table table-striped table-bordered dataTable' };
    const mockTableContainer = { id: 'daTable_wrapper', nodeType: 1 };

    const mockDtApi = {
        ajax: {
            params() {
                return {
                    draw: 4,
                    start: currentPage * currentPageLength,
                    length: currentPageLength,
                    search: { value: currentSearch, regex: false },
                    order: initialOrder.map(([column, dir]) => ({ column, dir })),
                    columns: (capturedDtConfig.columns || []).map((col) => ({
                        data: col.data,
                        name: col.name || '',
                        searchable: col.searchable !== false,
                        orderable: col.orderable !== false,
                        search: { value: '', regex: false }
                    }))
                };
            },
            reload(cb, resetPaging) {
                if (resetPaging === true) {
                    currentPage = 0;
                }
                reloadCalls.push({ cb: cb ?? null, resetPaging });
            }
        },
        search(val) {
            if (val === undefined) {
                return currentSearch;
            }
            searchCalls.push(val);
            currentSearch = val;
            currentPage = 0;
            return this;
        },
        order() {
            return initialOrder.map((pair) => pair.slice());
        },
        page: Object.assign(
            function (p) {
                if (p === undefined) return currentPage;
                currentPage = p;
                return this;
            },
            {
                len(l) {
                    if (l === undefined) return currentPageLength;
                    currentPageLength = l;
                    return this;
                }
            }
        ),
        settings() {
            return [{
                aoColumns: (capturedDtConfig.columns || []).map((col, idx) => ({
                    sTitle: headerLabels[idx] || col.data,
                    sClass: col.className || '',
                    bVisible: !hiddenColumns.includes(idx)
                }))
            }];
        },
        column(idx) {
            return {
                index() {
                    return idx;
                },
                visible() {
                    return !hiddenColumns.includes(idx);
                },
                header() {
                    return {
                        textContent: headerLabels[idx] || '',
                        innerHTML: headerLabels[idx] || ''
                    };
                }
            };
        },
        columns(selector) {
            const list = Array.isArray(selector) ? selector.slice() : [0, 1, 2, 3, 4];
            return {
                flatten() {
                    return {
                        map(fn) {
                            return {
                                toArray() {
                                    return list.map(fn);
                                }
                            };
                        }
                    };
                }
            };
        },
        table() {
            return {
                node() {
                    return mockTableNode;
                },
                container() {
                    return mockTableContainer;
                }
            };
        },
        i18n(key, def) {
            return def;
        },
        buttons: Object.assign(
            function (selector) {
                return {
                    enable(flag = true) {
                        exportButtonsDisabled = (flag === false);
                        buttonEnableCalls.push({ selector, enabled: flag !== false });
                        return this;
                    },
                    disable() {
                        exportButtonsDisabled = true;
                        buttonEnableCalls.push({ selector, enabled: false });
                        return this;
                    }
                };
            },
            {
                exportInfo(conf) {
                    return {
                        title: conf.title === '*' || !conf.title ? 'Log Activities' : conf.title,
                        filename: (conf.filename === '*' || !conf.filename ? 'Log Activities' : conf.filename) + (conf.extension || ''),
                        messageTop: null,
                        messageBottom: null
                    };
                },
                info() {}
            }
        )
    };

    const daTableEl = makeInteractiveEl('');
    daTableEl.DataTable = function (config) {
        capturedDtConfig = config;
        return mockDtApi;
    };

    const elements = {
        '#Company': makeInteractiveEl('5'),
        '#StartDate': makeInteractiveEl('2026-11-01'),
        '#EndDate': makeInteractiveEl('2026-11-06'),
        '#btnFilter': makeInteractiveEl(''),
        '#btnClear': makeInteractiveEl(''),
        'input[type="date"]': makeInteractiveEl(''),
        '#daTable': daTableEl
    };

    const domMock = createDomMock();
    const documentObj = {
        createElement(tag) {
            if (String(tag).toLowerCase() === 'audio') {
                audioElementsCreated++;
            }
            return domMock.createElement(tag);
        },
        createTextNode(text) {
            return domMock.createTextNode(text);
        }
    };

    const $ = (selector) => {
        if (selector === documentObj) {
            return {
                ready(fn) {
                    readyCallback = fn;
                }
            };
        }
        if (typeof selector === 'string' && selector.startsWith('meta[')) {
            return { attr: () => 'test-csrf-token' };
        }
        return elements[selector] || makeInteractiveEl('');
    };
    $.ajaxSetup = () => {};
    $.extend = function (deep, target) {
        let deepCopy = false;
        let startIdx = 1;
        let out = deep;
        if (typeof deep === 'boolean') {
            deepCopy = deep;
            out = target || {};
            startIdx = 2;
        } else {
            out = deep || {};
        }
        function merge(dst, src) {
            if (!src || typeof src !== 'object') return dst;
            Object.keys(src).forEach((k) => {
                const val = src[k];
                if (val === undefined) return;
                if (deepCopy && Array.isArray(val)) {
                    dst[k] = val.map((item) => (item && typeof item === 'object' ? merge(Array.isArray(item) ? [] : {}, item) : item));
                } else if (deepCopy && val && typeof val === 'object' && Object.prototype.toString.call(val) === '[object Object]') {
                    dst[k] = merge(dst[k] && typeof dst[k] === 'object' ? dst[k] : {}, val);
                } else {
                    dst[k] = val;
                }
            });
            return dst;
        }
        for (let i = startIdx; i < arguments.length; i++) {
            merge(out, arguments[i]);
        }
        return out;
    };
    $.ajax = function (opts) {
        const reqRecord = {
            opts,
            resolve(payload) {
                if (opts && typeof opts.success === 'function') {
                    opts.success(payload);
                }
                if (opts && typeof opts.complete === 'function') {
                    opts.complete({}, 'success');
                }
            },
            reject(xhr, statusText = 'error') {
                if (opts && typeof opts.error === 'function') {
                    opts.error(xhr, statusText);
                }
                if (opts && typeof opts.complete === 'function') {
                    opts.complete(xhr, statusText);
                }
            }
        };
        ajaxRequests.push(reqRecord);
        return reqRecord;
    };

    const nativeButtonConfigs = {
        copyHtml5: { extension: '.txt', header: true, footer: false, title: '*', filename: '*' },
        excelHtml5: { extension: '.xlsx', header: true, footer: false, title: '*', filename: '*' },
        pdfHtml5: { extension: '.pdf', header: true, footer: false, title: '*', filename: '*' },
        print: { extension: '', header: true, footer: false, title: '*', filename: '*' }
    };

    const extButtons = {};
    ['copyHtml5', 'excelHtml5', 'pdfHtml5', 'print'].forEach((nativeKey) => {
        extButtons[nativeKey] = {
            ...nativeButtonConfigs[nativeKey],
            action: function (e, dt, node, config) {
                const exported = nativeKey === 'print'
                    ? dt.buttons.exportData($.extend(true, { decodeEntities: false }, config.exportOptions))
                    : dt.buttons.exportData(config.exportOptions);
                nativeExportCalls.push({
                    nativeKey,
                    dt,
                    config,
                    exported,
                    tableClassName: dt.table().node().className
                });
                if (nativeKey !== 'print') {
                    this.processing(false);
                }
            }
        };
    });

    $.fn = {
        dataTable: {
            ext: { buttons: extButtons }
        }
    };

    const toastr = withToastr ? {
        info(msg) { toastrCalls.info.push(msg); },
        warning(msg) { toastrCalls.warning.push(msg); },
        error(msg) { toastrCalls.error.push(msg); }
    } : undefined;

    const contextObj = {
        $,
        document: documentObj,
        alert: (msg) => { alertCalls.push(msg); },
        Number,
        String,
        Boolean,
        Object,
        Array,
        Error,
        RegExp
    };
    if (toastr) {
        contextObj.toastr = toastr;
    }

    const context = vm.createContext(contextObj);
    const scriptCode = extractInlineScript(bladeSource);
    vm.runInContext(scriptCode, context);
    readyCallback();

    function clickExportButton(extendName) {
        const idx = capturedDtConfig.buttons.findIndex(
            (b) => b.extend === extendName && !/Refresh/i.test(String(b.text || ''))
        );
        assert.notEqual(idx, -1, `Expected export button for ${extendName}`);
        const btnCfg = capturedDtConfig.buttons[idx];
        const processingStates = [];
        const btnApi = {
            processing(flag) {
                if (flag === undefined) {
                    return processingStates[processingStates.length - 1] || false;
                }
                processingStates.push(Boolean(flag));
                return this;
            }
        };
        const mergedConfig = $.extend(true, {}, nativeButtonConfigs[extendName === 'print' ? 'print' : `${extendName}Html5`], btnCfg);
        btnCfg.action.call(btnApi, { preventDefault() {} }, mockDtApi, {}, mergedConfig);
        return { btnApi, processingStates, mergedConfig };
    }

    return {
        context,
        capturedDtConfig,
        elements,
        mockDtApi,
        clickExportButton,
        getReloadCalls: () => reloadCalls,
        getSearchCalls: () => searchCalls,
        getAjaxRequests: () => ajaxRequests,
        getNativeExportCalls: () => nativeExportCalls,
        getButtonEnableCalls: () => buttonEnableCalls,
        isExportButtonsDisabled: () => exportButtonsDisabled,
        getCurrentPage: () => currentPage,
        getCurrentPageLength: () => currentPageLength,
        getToastrCalls: () => toastrCalls,
        getAlertCalls: () => alertCalls,
        getAudioElementsCreated: () => audioElementsCreated
    };
}

function fromVm(val) {
    return JSON.parse(JSON.stringify(val));
}

test('Log Activities filter Enter/Clear/Search UX and full-report export (Copy, Excel, PDF, Print)', async (t) => {
    await t.test('Enter inside #StartDate/#EndDate prevents form submit and reloads page 1; #btnClear clears global search; Refresh keeps page', () => {
        const h = createFilterAndExportHarness({ initialPage: 3, initialSearch: 'Alert' });

        // Pressing Enter on #StartDate & #EndDate triggers reload(null, true) and calls preventDefault()
        ['#StartDate', '#EndDate'].forEach((selector) => {
            let prevented = false;
            h.elements[selector].trigger('keydown', {
                key: 'Enter',
                keyCode: 13,
                preventDefault() { prevented = true; }
            });
            assert.equal(prevented, true, `Enter on ${selector} must call preventDefault()`);
        });
        assert.equal(h.getReloadCalls().length, 2);
        assert.equal(h.getReloadCalls()[0].resetPaging, true);
        assert.equal(h.getReloadCalls()[1].resetPaging, true);
        assert.equal(h.elements['#StartDate'].val(), '2026-11-01');
        assert.equal(h.elements['#EndDate'].val(), '2026-11-06');

        // Inverted date range warns non-blockingly via toastr while still reloading
        h.elements['#StartDate'].val('2026-11-10');
        h.elements['#EndDate'].val('2026-11-01');
        h.elements['#btnFilter'].trigger('click');
        assert.equal(h.getToastrCalls().warning.length, 1);

        // #btnClear clears #Company, #StartDate, #EndDate, global Table.search(''), and resets to page 1
        h.elements['#btnClear'].trigger('click');
        assert.equal(h.elements['#Company'].val(), '');
        assert.equal(h.elements['#StartDate'].val(), '');
        assert.equal(h.elements['#EndDate'].val(), '');
        assert.deepEqual(h.getSearchCalls(), ['']);
        const lastReload = h.getReloadCalls()[h.getReloadCalls().length - 1];
        assert.equal(lastReload.resetPaging, true);

        // Refresh button keeps current page (resetPaging === false)
        const refreshBtn = h.capturedDtConfig.buttons.find((b) => /Refresh/i.test(String(b.text || '')));
        assert.ok(refreshBtn, 'Refresh button must exist');
        refreshBtn.action();
        const refreshReload = h.getReloadCalls()[h.getReloadCalls().length - 1];
        assert.equal(refreshReload.resetPaging, false);
    });

    await t.test('Copy, Excel, PDF, and Print export ALL filtered/sorted rows (length: -1) with entity & XSS safety and leave visible pagination undisturbed', () => {
        const sampleRows = Array.from({ length: 15 }, (_, idx) => {
            if (idx === 0) {
                return {
                    id: 1,
                    created_at: '10 Nov 2026 09:00 AWST',
                    company: 'O&#039;Reilly &amp; Sons',
                    name: 'Alice &quot;Admin&quot;',
                    activity: 'Updating Location',
                    latitude: -31.9505,
                    longitude: 115.8605,
                    attachment: 'audio/clip-1.mp3'
                };
            }
            if (idx === 1) {
                return {
                    id: 2,
                    created_at: '10 Nov 2026 09:01 AWST',
                    company: 'XSS &amp; Co',
                    name: 'Eve',
                    activity: 'Checked &lt;img src=x onerror=alert(9)&gt; &lt;script&gt;alert(1)&lt;/script&gt;',
                    attachment: null
                };
            }
            return {
                id: idx + 1,
                created_at: `10 Nov 2026 09:${String(idx).padStart(2, '0')} AWST`,
                company: 'Acme Corp',
                name: `Operator ${idx + 1}`,
                activity: `Batch Activity ${idx + 1}`,
                attachment: null
            };
        });

        [
            { extend: 'copy', expectedNative: 'copyHtml5', expectsDecoded: true },
            { extend: 'excel', expectedNative: 'excelHtml5', expectsDecoded: true },
            { extend: 'pdf', expectedNative: 'pdfHtml5', expectsDecoded: true },
            { extend: 'print', expectedNative: 'print', expectsDecoded: false }
        ].forEach(({ extend, expectedNative, expectsDecoded }) => {
            const h = createFilterAndExportHarness({
                initialPage: 4,
                initialPageLength: 10,
                initialSearch: 'Batch',
                initialOrder: [[3, 'asc']]
            });

            const { processingStates } = h.clickExportButton(extend);
            assert.equal(h.getAjaxRequests().length, 1, `${extend} must initiate full-report AJAX request`);

            const req = h.getAjaxRequests()[0];
            assert.equal(req.opts.url, '/admin/log-activity');
            assert.equal(req.opts.type, 'GET');
            assert.equal(req.opts.data.company_id, '5');
            assert.equal(req.opts.data.start_date, '2026-11-01');
            assert.equal(req.opts.data.end_date, '2026-11-06');
            assert.equal(req.opts.data.search.value, 'Batch');
            assert.deepEqual(fromVm(req.opts.data.order), [{ column: 3, dir: 'asc' }]);
            assert.equal(req.opts.data.start, 0);
            assert.equal(req.opts.data.length, -1);
            assert.equal(h.isExportButtonsDisabled(), true);

            // Resolve server response with all 15 rows across multiple pages
            req.resolve({
                draw: req.opts.data.draw,
                recordsTotal: 50,
                recordsFiltered: 15,
                data: sampleRows
            });

            const exports = h.getNativeExportCalls();
            assert.equal(exports.length, 1);
            assert.equal(exports[0].nativeKey, expectedNative);
            assert.deepEqual(fromVm(exports[0].exported.header), ['CREATED AT', 'COMPANY', 'NAME', 'ACTIVITY']);
            assert.equal(exports[0].exported.body.length, 15, `${extend} must export all 15 filtered rows, not 10`);

            // ATTACHMENT column excluded, zero <audio> DOM elements created, location row has clean text without coords
            assert.equal(h.getAudioElementsCreated(), 0);
            assert.equal(exports[0].exported.body[0].length, 4);
            assert.equal(exports[0].exported.body[0][3], 'Updating Location');

            // Copy/Excel/PDF decode entities cleanly once; Print keeps HTML entities escaped for XSS safety
            if (expectsDecoded) {
                assert.equal(exports[0].exported.body[0][1], "O'Reilly & Sons");
                assert.equal(exports[0].exported.body[0][2], 'Alice "Admin"');
                assert.equal(exports[0].exported.body[1][3], 'Checked <img src=x onerror=alert(9)> <script>alert(1)</script>');
            } else {
                assert.equal(exports[0].exported.body[0][1], 'O&#39;Reilly &amp; Sons');
                assert.equal(exports[0].exported.body[1][3], 'Checked &lt;img src=x onerror=alert(9)&gt; &lt;script&gt;alert(1)&lt;/script&gt;');
            }

            // Visible DataTable pagination & page length preserved; export never calls ajax.reload()
            assert.equal(h.getCurrentPage(), 4);
            assert.equal(h.getCurrentPageLength(), 10);
            assert.equal(h.getReloadCalls().length, 0);

            // Processing cleared and export buttons re-enabled
            assert.equal(processingStates[0], true);
            assert.equal(processingStates[processingStates.length - 1], false);
            assert.equal(h.isExportButtonsDisabled(), false);
        });

        // Respects hidden column exclusion (e.g. hiding COMPANY column index 1)
        const hHidden = createFilterAndExportHarness({ hiddenColumns: [1] });
        hHidden.clickExportButton('excel');
        hHidden.getAjaxRequests()[0].resolve({ data: [sampleRows[0]] });
        assert.deepEqual(fromVm(hHidden.getNativeExportCalls()[0].exported.header), ['CREATED AT', 'NAME', 'ACTIVITY']);
        assert.equal(hHidden.getNativeExportCalls()[0].exported.body[0].length, 3);
    });

    await t.test('Export guards against duplicate clicks, empty datasets, and AJAX failures (always recovering button/processing state)', () => {
        // 1. Concurrent click prevention & empty 0-row response cleanup
        const hEmpty = createFilterAndExportHarness();
        const firstClick = hEmpty.clickExportButton('pdf');
        hEmpty.clickExportButton('excel'); // ignored while PDF request in flight
        assert.equal(hEmpty.getAjaxRequests().length, 1);

        hEmpty.getAjaxRequests()[0].resolve({ recordsTotal: 0, recordsFiltered: 0, data: [] });
        assert.equal(hEmpty.getNativeExportCalls().length, 0);
        assert.equal(hEmpty.getToastrCalls().info.length, 1);
        assert.equal(firstClick.processingStates[firstClick.processingStates.length - 1], false);
        assert.equal(hEmpty.isExportButtonsDisabled(), false);

        // Lock released after completion -> subsequent click succeeds
        hEmpty.clickExportButton('copy');
        assert.equal(hEmpty.getAjaxRequests().length, 2);

        // 2. AJAX HTTP 422 failure with toastr surfaces server error and cleans up
        const hErr = createFilterAndExportHarness({ withToastr: true });
        const errClick = hErr.clickExportButton('excel');
        hErr.getAjaxRequests()[0].reject({ status: 422, responseJSON: { status: 'error', message: 'Export limit exceeded (12,000 rows).' } });
        assert.equal(hErr.getNativeExportCalls().length, 0);
        assert.equal(hErr.getToastrCalls().error.length, 1);
        assert.match(hErr.getToastrCalls().error[0], /Export limit exceeded/);
        assert.equal(errClick.processingStates[errClick.processingStates.length - 1], false);
        assert.equal(hErr.isExportButtonsDisabled(), false);

        // 3. AJAX failure without toastr falls back to alert() cleanly
        const hNoToastr = createFilterAndExportHarness({ withToastr: false });
        hNoToastr.clickExportButton('print');
        hNoToastr.getAjaxRequests()[0].reject({ status: 500 });
        assert.equal(hNoToastr.getAlertCalls().length, 1);
        assert.equal(hNoToastr.isExportButtonsDisabled(), false);
    });

    await t.test('Company and Name columns remain searchable in DataTables config while non-orderable', () => {
        const h = createFilterAndExportHarness();
        const cols = fromVm(vm.runInContext('logActivityColumns', h.context));
        const companyCol = cols.find((c) => c.data === 'company');
        const nameCol = cols.find((c) => c.data === 'name');
        assert.ok(companyCol, 'Expected company column definition');
        assert.ok(nameCol, 'Expected name column definition');
        assert.equal(companyCol.searchable, true);
        assert.equal(companyCol.orderable, false);
        assert.equal(nameCol.searchable, true);
        assert.equal(nameCol.orderable, false);
    });
});


