const { test, describe, beforeEach, afterEach } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const dashboardBladePath = path.resolve(__dirname, '../../resources/views/pages/dashboard.blade.php');
const dashboardBladeSource = fs.readFileSync(dashboardBladePath, 'utf8');

function extractDashboardScript(source) {
    const scripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)];
    assert.ok(scripts.length > 0, 'Expected inline <script> block in dashboard.blade.php');
    return scripts[scripts.length - 1][1]
        .replace(/\{\{\s*route\(['"]auth\.dashboard\.markers['"]\)\s*\}\}/g, '/dashboard/markers');
}

function createDashboardHarness() {
    let timerSeq = 1;
    const intervals = new Map();
    const clearIntervalCalls = [];
    const windowListeners = {};
    const documentListeners = {};
    const toastrCalls = [];
    const ajaxCalls = [];
    const createdMarkers = [];
    const mapCalls = {
        setView: [],
        flyTo: [],
        fitBounds: [],
        initialLayers: [],
        tileLayers: []
    };
    const layerCalls = {
        added: [],
        removed: [],
        clearedCount: 0
    };
    let readyCallback = null;

    const createMockMarker = (latLng) => {
        const marker = {
            latLng,
            popupContent: null,
            popupOpen: false,
            handlers: {},
            getLatLng() {
                return { lat: this.latLng[0], lng: this.latLng[1] };
            },
            setLatLng(next) {
                this.latLng = next;
                return this;
            },
            bindPopup(content) {
                this.popupContent = content;
                return this;
            },
            setPopupContent(content) {
                this.popupContent = content;
                return this;
            },
            getPopup() {
                return { getContent: () => this.popupContent };
            },
            isPopupOpen() {
                return this.popupOpen;
            },
            openPopup() {
                this.popupOpen = true;
                return this;
            },
            on(evt, fn) {
                this.handlers[evt] = fn;
                return this;
            }
        };
        createdMarkers.push(marker);
        return marker;
    };

    const mockLayerGroup = {
        addTo() { return this; },
        addLayer(layer) { layerCalls.added.push(layer); },
        removeLayer(layer) { layerCalls.removed.push(layer); },
        clearLayers() { layerCalls.clearedCount++; }
    };

    const mockMap = {
        setView(center, zoom) { mapCalls.setView.push({ center, zoom }); },
        flyTo(center, zoom) { mapCalls.flyTo.push({ center, zoom }); },
        fitBounds(bounds, opts) { mapCalls.fitBounds.push({ bounds, opts }); },
        hasLayer() { return true; }
    };

    const L = {
        tileLayer: (url) => {
            const layer = { url };
            mapCalls.tileLayers.push(layer);
            return layer;
        },
        map: (id, options) => {
            mapCalls.initialLayers = options.layers;
            return mockMap;
        },
        control: { layers: () => ({ addTo: () => ({}) }) },
        LayerGroup: function () { return mockLayerGroup; },
        marker: (latLng) => createMockMarker(latLng),
        latLngBounds: (latLngs) => ({ latLngs })
    };

    const moment = (value) => {
        const api = {
            tz(zone) {
                return { format: () => `${value} [${zone}]` };
            },
            format() {
                return `${value}`;
            }
        };
        return api;
    };

    const document = {
        hidden: false,
        createElement(tag) {
            return {
                tagName: tag.toUpperCase(),
                children: [],
                appendChild(child) {
                    this.children.push(child);
                    return child;
                }
            };
        },
        createTextNode(text) {
            return { nodeType: 3, textContent: String(text) };
        },
        addEventListener(evt, handler) {
            documentListeners[evt] = documentListeners[evt] || [];
            documentListeners[evt].push(handler);
        },
        dispatchVisibilityChange(hiddenValue) {
            this.hidden = hiddenValue;
            (documentListeners.visibilitychange || []).forEach((fn) => fn());
        }
    };

    const windowObj = {
        addEventListener(evt, handler) {
            windowListeners[evt] = windowListeners[evt] || [];
            windowListeners[evt].push(handler);
        },
        dispatchEvent(evt) {
            (windowListeners[evt] || []).forEach((fn) => fn());
        }
    };

    const $ = (arg) => {
        if (arg === document) {
            return {
                ready(fn) {
                    readyCallback = fn;
                }
            };
        }
        return {};
    };

    $.each = (items, cb) => {
        if (Array.isArray(items)) {
            items.forEach((v, i) => cb(i, v));
        } else if (items && typeof items === 'object') {
            Object.keys(items).forEach((k) => cb(k, items[k]));
        }
    };

    $.ajax = (opts) => {
        const xhr = {
            aborted: false,
            abortCount: 0,
            opts,
            abort() {
                this.abortCount++;
                this.aborted = true;
                if (typeof opts.error === 'function') {
                    opts.error(this, 'abort');
                }
                if (typeof opts.complete === 'function') {
                    opts.complete(this, 'abort');
                }
            },
            resolveSuccess(res) {
                if (typeof opts.success === 'function') {
                    opts.success(res);
                }
                if (typeof opts.complete === 'function') {
                    opts.complete(this, 'success');
                }
            },
            rejectError(status, textStatus = 'error', responseText = '', contentType = 'application/json') {
                this.status = status;
                this.responseText = responseText;
                this.getResponseHeader = (name) => {
                    if (String(name).toLowerCase() === 'content-type') {
                        return contentType;
                    }
                    return null;
                };
                if (typeof opts.error === 'function') {
                    opts.error(this, textStatus);
                }
                if (typeof opts.complete === 'function') {
                    opts.complete(this, textStatus);
                }
            }
        };
        ajaxCalls.push(xhr);
        return xhr;
    };

    const toastr = {
        error(message, title, opts) {
            toastrCalls.push({ level: 'error', message, title, opts });
        },
        success(message, title, opts) {
            toastrCalls.push({ level: 'success', message, title, opts });
        }
    };

    const setIntervalStub = (fn, ms) => {
        const id = timerSeq++;
        intervals.set(id, { fn, ms });
        return id;
    };

    const clearIntervalStub = (id) => {
        clearIntervalCalls.push(id);
        intervals.delete(id);
    };

    const context = vm.createContext({
        $,
        L,
        moment,
        toastr,
        document,
        window: windowObj,
        setInterval: setIntervalStub,
        clearInterval: clearIntervalStub,
        Array,
        Number,
        Object,
        String
    });

    const scriptCode = extractDashboardScript(dashboardBladeSource);
    vm.runInContext(scriptCode, context);

    return {
        context,
        document,
        window: windowObj,
        intervals,
        clearIntervalCalls,
        toastrCalls,
        ajaxCalls,
        createdMarkers,
        mapCalls,
        layerCalls,
        triggerReady() {
            if (readyCallback) readyCallback();
        },
        tickInterval() {
            const entries = Array.from(intervals.values());
            entries.forEach((item) => item.fn());
        },
        cleanup() {
            if (typeof context.stopMarkersPolling === 'function') {
                context.stopMarkersPolling();
            }
            intervals.clear();
        }
    };
}

describe('Dashboard Markers Real Blade JS Behavior', () => {
    let harness;

    beforeEach(() => {
        harness = createDashboardHarness();
    });

    afterEach(() => {
        harness.cleanup();
    });

    test('Initial map keeps Google Satellites as the sole active base layer over HTTPS with English locale', () => {
        assert.strictEqual(harness.mapCalls.initialLayers.length, 1);
        assert.match(harness.mapCalls.initialLayers[0].url, /^https:\/\/\{s\}\.google\.com\/vt\/lyrs=s,h/);
        assert.match(harness.mapCalls.initialLayers[0].url, /[?&]hl=en(?:&|$)/);

        const googleTileUrls = harness.mapCalls.tileLayers
            .map((layer) => layer.url)
            .filter((url) => url.includes('google.com/vt'));

        assert.strictEqual(googleTileUrls.length, 3, 'Expected googleTile, googleTerrain, and googleSat layers');
        googleTileUrls.forEach((url) => {
            assert.match(url, /^https:\/\//, `Expected HTTPS Google tile URL, got: ${url}`);
            assert.match(url, /[?&]hl=en(?:&|$)/, `Expected hl=en parameter on Google tile URL, got: ${url}`);
        });
    });

    test('Initial load fits bounds, subsequent updates preserve viewport and open popups, removed devices detach', () => {
        harness.triggerReady();
        assert.strictEqual(harness.ajaxCalls.length, 1);
        assert.strictEqual(harness.intervals.size, 1);

        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Device 1', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-06 10:00' } },
                { id: 2, name: 'Device 2', code: 'Australia/Perth', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-11-06 10:00' } }
            ]
        });

        assert.strictEqual(harness.mapCalls.fitBounds.length, 1);
        assert.strictEqual(harness.layerCalls.clearedCount, 0);
        assert.strictEqual(harness.layerCalls.added.length, 2);

        const marker1 = harness.context.markersById[1];
        marker1.openPopup();

        harness.tickInterval();
        assert.strictEqual(harness.ajaxCalls.length, 2);
        harness.ajaxCalls[1].resolveSuccess({
            data: [
                { id: 1, name: 'Device 1', code: 'Australia/Perth', position: { latitude: -31.96, longitude: 115.87, created_at: '2026-11-06 10:01' } }
            ]
        });

        assert.strictEqual(harness.mapCalls.fitBounds.length, 1, 'Subsequent poll must not re-run fitBounds');
        assert.strictEqual(harness.context.markersById[1], marker1, 'Existing marker instance must be retained');
        assert.strictEqual(marker1.getLatLng().lat, -31.96);
        assert.strictEqual(marker1.isPopupOpen(), true, 'Open popup must remain open');
        assert.strictEqual(harness.context.markersById[2], undefined, 'Removed device must be deleted');
        assert.strictEqual(harness.layerCalls.removed.length, 1);
    });

    test('401 error stops polling, notifies the user, and stays stopped when visibility changes', () => {
        harness.triggerReady();
        harness.ajaxCalls[0].rejectError(401, 'error', JSON.stringify({ message: 'Unauthenticated.' }));

        assert.strictEqual(harness.intervals.size, 0);
        assert.strictEqual(harness.context.isFetchingMarkers, false);
        assert.strictEqual(harness.toastrCalls.length, 1);
        assert.strictEqual(harness.toastrCalls[0].level, 'error');

        harness.document.dispatchVisibilityChange(true);
        harness.document.dispatchVisibilityChange(false);
        assert.strictEqual(harness.ajaxCalls.length, 1);
        assert.strictEqual(harness.intervals.size, 0);
    });

    test('419 CSRF expiration stops polling and notifies the user', () => {
        harness.triggerReady();
        harness.ajaxCalls[0].rejectError(419, 'error', 'CSRF token mismatch');

        assert.strictEqual(harness.intervals.size, 0);
        assert.strictEqual(harness.context.isFetchingMarkers, false);
        assert.strictEqual(harness.toastrCalls.length, 1);
        assert.strictEqual(harness.toastrCalls[0].level, 'error');
    });

    test('parsererror stops polling and notifies the user', () => {
        harness.triggerReady();
        harness.ajaxCalls[0].rejectError(200, 'parsererror', '<!DOCTYPE html><html><title>Login</title></html>', 'text/html');

        assert.strictEqual(harness.intervals.size, 0);
        assert.strictEqual(harness.context.isFetchingMarkers, false);
        assert.strictEqual(harness.toastrCalls.length, 1);
        assert.strictEqual(harness.toastrCalls[0].level, 'error');
    });

    test('Aborted requests are ignored silently without error toast', () => {
        harness.triggerReady();
        assert.strictEqual(harness.ajaxCalls.length, 1);

        harness.window.dispatchEvent('pagehide');
        assert.strictEqual(harness.ajaxCalls[0].abortCount, 1);
        assert.strictEqual(harness.intervals.size, 0);
        assert.strictEqual(harness.context.isFetchingMarkers, false);
        assert.strictEqual(harness.toastrCalls.length, 0, 'Abort must not show error notification');
    });

    test('Transient 5xx / network timeout errors preserve polling and reset isFetchingMarkers in complete', () => {
        harness.triggerReady();
        assert.strictEqual(harness.intervals.size, 1);

        harness.ajaxCalls[0].rejectError(502, 'error', '<html><body>502 Bad Gateway</body></html>', 'text/html');
        assert.strictEqual(harness.intervals.size, 1, 'Transient 502 must keep polling active');
        assert.strictEqual(harness.context.isFetchingMarkers, false, 'complete must reset isFetchingMarkers');
        assert.strictEqual(harness.toastrCalls.length, 0, 'Transient 502 must not stop polling with auth toast');

        // Next tick continues working
        harness.tickInterval();
        assert.strictEqual(harness.ajaxCalls.length, 2);
        harness.ajaxCalls[1].rejectError(0, 'timeout', '');
        assert.strictEqual(harness.intervals.size, 1, 'Transient timeout must keep polling active');
        assert.strictEqual(harness.context.isFetchingMarkers, false);
    });

    test('Page visibility pauses the timer without aborting and resumes without overlapping', () => {
        harness.triggerReady();
        assert.strictEqual(harness.intervals.size, 1);

        harness.ajaxCalls[0].resolveSuccess({ data: [] });
        harness.tickInterval();
        assert.strictEqual(harness.ajaxCalls.length, 2);
        assert.strictEqual(harness.context.isFetchingMarkers, true);

        harness.document.dispatchVisibilityChange(true);
        assert.strictEqual(harness.intervals.size, 0);
        assert.strictEqual(harness.ajaxCalls[1].abortCount, 0);
        assert.strictEqual(harness.context.isFetchingMarkers, true);

        harness.document.dispatchVisibilityChange(false);
        assert.strictEqual(harness.intervals.size, 1);
        assert.strictEqual(harness.ajaxCalls.length, 2);

        harness.ajaxCalls[1].resolveSuccess({ data: [] });
        harness.document.dispatchVisibilityChange(true);
        harness.document.dispatchVisibilityChange(false);
        assert.strictEqual(harness.ajaxCalls.length, 3);
        assert.strictEqual(harness.ajaxCalls[2].abortCount, 0);
    });

    test('refreshMarkers initiates request and restarts timer without self-aborting', () => {
        harness.triggerReady();
        const timerBeforeRefresh = [...harness.intervals.keys()][0];
        harness.context.refreshMarkers();
        assert.strictEqual(harness.ajaxCalls.length, 1, 'A refresh must not overlap the initial request');
        assert.strictEqual(harness.ajaxCalls[0].abortCount, 0, 'Refresh must not abort the active XHR');
        assert.strictEqual(harness.intervals.size, 1, 'Refresh must leave exactly one polling timer');
        assert.deepStrictEqual(harness.clearIntervalCalls, [timerBeforeRefresh], 'Refresh must reset the existing timer');

        harness.tickInterval();
        assert.strictEqual(harness.ajaxCalls.length, 1, 'Polling must respect the active request guard');

        harness.ajaxCalls[0].resolveSuccess({ data: [] });
        harness.context.refreshMarkers();
        assert.strictEqual(harness.ajaxCalls.length, 2, 'Refresh starts a request once the previous request completes');
        assert.strictEqual(harness.ajaxCalls[1].abortCount, 0, 'Refresh must not abort its own XHR');
        assert.strictEqual(harness.intervals.size, 1, 'Repeated refreshes must not create duplicate timers');
    });
});
