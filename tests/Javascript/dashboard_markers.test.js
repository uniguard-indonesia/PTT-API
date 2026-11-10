process.env.TZ = 'UTC';

const { test, describe, beforeEach, afterEach } = require('node:test');
const assert = require('node:assert');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const dashboardBladePath = path.resolve(__dirname, '../../resources/views/pages/dashboard.blade.php');
const dashboardBladeSource = fs.readFileSync(dashboardBladePath, 'utf8');
const momentMinPath = path.resolve(__dirname, '../../public/assets/plugins/moment/moment.min.js');
const momentTzMinPath = path.resolve(__dirname, '../../public/assets/plugins/moment/moment-timezone.min.js');
const momentMinSource = fs.readFileSync(momentMinPath, 'utf8');
const momentTzMinSource = fs.readFileSync(momentTzMinPath, 'utf8');

function extractDashboardScript(source) {
    const scripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)];
    assert.ok(scripts.length > 0, 'Expected inline <script> block in dashboard.blade.php');
    return scripts[scripts.length - 1][1]
        .replace(/\{\{\s*route\(['"]auth\.dashboard\.markers['"]\)\s*\}\}/g, '/dashboard/markers');
}

function formatLocalSqlTimestamp(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function parseStubMomentDate(value) {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : new Date(value.getTime());
    }
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }
    const trimmed = value.trim();
    const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?(Z|[+-]\d{2}(?::?\d{2})?)?$/i.exec(trimmed);
    if (!match) {
        return null;
    }
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const hasTime = match[4] !== undefined;
    const hour = hasTime ? Number(match[4]) : 0;
    const minute = hasTime ? Number(match[5]) : 0;
    const second = match[6] !== undefined ? Number(match[6]) : 0;
    const ms = match[7] !== undefined ? Number(match[7].slice(0, 3).padEnd(3, '0')) : 0;
    const tz = match[8];
    if ((!hasTime && tz) || month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || second > 59) {
        return null;
    }
    if (tz) {
        const utcCheck = new Date(Date.UTC(year, month - 1, day, hour, minute, second, ms));
        if (
            utcCheck.getUTCFullYear() !== year ||
            utcCheck.getUTCMonth() !== month - 1 ||
            utcCheck.getUTCDate() !== day ||
            utcCheck.getUTCHours() !== hour ||
            utcCheck.getUTCMinutes() !== minute ||
            utcCheck.getUTCSeconds() !== second
        ) {
            return null;
        }
        let offsetMinutes = 0;
        if (tz.toUpperCase() !== 'Z') {
            const sign = tz.charAt(0) === '-' ? -1 : 1;
            const digits = tz.slice(1).replace(':', '');
            const tzHours = Number(digits.slice(0, 2));
            const tzMins = digits.length === 4 ? Number(digits.slice(2, 4)) : 0;
            if (tzHours > 23 || tzMins > 59) {
                return null;
            }
            offsetMinutes = sign * (tzHours * 60 + tzMins);
        }
        return new Date(utcCheck.getTime() - offsetMinutes * 60000);
    }
    const local = new Date(year, month - 1, day, hour, minute, second, ms);
    if (
        Number.isNaN(local.getTime()) ||
        local.getFullYear() !== year ||
        local.getMonth() !== month - 1 ||
        local.getDate() !== day
    ) {
        return null;
    }
    return local;
}

function createDashboardHarness(options = {}) {
    let timerSeq = 1;
    let currentNowMs = options.now !== undefined ? new Date(options.now).getTime() : Date.now();
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

    class HarnessDate extends Date {
        constructor(...args) {
            if (args.length === 0) {
                super(currentNowMs);
            } else {
                super(...args);
            }
        }

        static now() {
            return currentNowMs;
        }
    }

    const checkboxTagMatch = dashboardBladeSource.match(/<input\b[^>]*\bid=["']hideStalePinsCheckbox["'][^>]*>/i);
    const checkboxEl = checkboxTagMatch
        ? {
            id: 'hideStalePinsCheckbox',
            type: 'checkbox',
            checked: /\bchecked(?:\s*=\s*(?:["']checked["']|["']true["']|true))?\b/i.test(checkboxTagMatch[0]),
            listeners: {},
            addEventListener(evt, fn) {
                this.listeners[evt] = this.listeners[evt] || [];
                this.listeners[evt].push(fn);
            },
            dispatchEvent(evt) {
                const eventName = typeof evt === 'string' ? evt : (evt && evt.type);
                (this.listeners[eventName] || []).forEach((fn) => fn.call(this, { type: eventName, target: this }));
            }
        }
        : null;

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

    const wrapMoment = (rawValue, dateObj) => {
        const api = {
            _date: dateObj,
            isValid() {
                return this._date !== null && !Number.isNaN(this._date.getTime());
            },
            valueOf() {
                return this._date ? this._date.getTime() : NaN;
            },
            toDate() {
                return this._date ? new Date(this._date.getTime()) : new Date(NaN);
            },
            subtract(amount, unit) {
                if (!this.isValid()) {
                    return wrapMoment(rawValue, null);
                }
                const next = new Date(this._date.getTime());
                if (unit === 'month' || unit === 'months' || unit === 'M') {
                    const origDay = next.getDate();
                    const totalMonths = next.getFullYear() * 12 + next.getMonth() - amount;
                    const targetYear = Math.floor(totalMonths / 12);
                    const targetMonth = ((totalMonths % 12) + 12) % 12;
                    const daysInTarget = new Date(targetYear, targetMonth + 1, 0).getDate();
                    next.setFullYear(targetYear, targetMonth, Math.min(origDay, daysInTarget));
                } else if (unit === 'day' || unit === 'days' || unit === 'd') {
                    next.setDate(next.getDate() - amount);
                }
                return wrapMoment(next.toISOString(), next);
            },
            isBefore(other) {
                const otherMs = other && typeof other.valueOf === 'function' ? other.valueOf() : new Date(other).getTime();
                return this.isValid() && !Number.isNaN(otherMs) && this._date.getTime() < otherMs;
            },
            isAfter(other) {
                const otherMs = other && typeof other.valueOf === 'function' ? other.valueOf() : new Date(other).getTime();
                return this.isValid() && !Number.isNaN(otherMs) && this._date.getTime() > otherMs;
            },
            tz(zone) {
                return { format: () => `${rawValue} [${zone}]` };
            },
            format() {
                return `${rawValue}`;
            }
        };
        return api;
    };

    const moment = (value) => {
        if (value === undefined) {
            const nowDate = new HarnessDate(currentNowMs);
            return wrapMoment(nowDate.toISOString(), nowDate);
        }
        return wrapMoment(value, parseStubMomentDate(value));
    };

    const document = {
        hidden: false,
        getElementById(id) {
            if (id === 'hideStalePinsCheckbox') {
                return checkboxEl;
            }
            return null;
        },
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

    const createCheckboxJqueryWrapper = (el) => {
        const wrapper = {
            length: el ? 1 : 0,
            is(selector) {
                if (!el) return false;
                if (selector === ':checked') {
                    return Boolean(el.checked);
                }
                return false;
            },
            prop(name, value) {
                if (arguments.length === 1) {
                    return el ? el[name] : undefined;
                }
                if (el) {
                    el[name] = name === 'checked' ? Boolean(value) : value;
                }
                return wrapper;
            },
            on(events, handler) {
                if (!el || typeof handler !== 'function') {
                    return wrapper;
                }
                String(events).split(/\s+/).filter(Boolean).forEach((evt) => {
                    el.addEventListener(evt, handler);
                });
                return wrapper;
            },
            trigger(evt) {
                if (el) {
                    el.dispatchEvent(evt);
                }
                return wrapper;
            },
            get(index) {
                return index === 0 ? el : undefined;
            }
        };
        return wrapper;
    };

    const $ = (arg) => {
        if (arg === document) {
            return {
                ready(fn) {
                    readyCallback = fn;
                }
            };
        }
        if (arg === '#hideStalePinsCheckbox' || (checkboxEl && arg === checkboxEl)) {
            return createCheckboxJqueryWrapper(checkboxEl);
        }
        return {
            length: 0,
            is: () => false,
            prop: () => undefined,
            on() { return this; },
            trigger() { return this; }
        };
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

    const contextGlobals = {
        $,
        L,
        toastr,
        document,
        window: windowObj,
        setInterval: setIntervalStub,
        clearInterval: clearIntervalStub,
        Array,
        Date: HarnessDate,
        Number,
        Object,
        String
    };
    if (!options.useRealMoment) {
        contextGlobals.moment = moment;
    }
    const context = vm.createContext(contextGlobals);

    if (options.useRealMoment) {
        vm.runInContext(momentMinSource, context);
        vm.runInContext(momentTzMinSource, context);
    }

    const scriptCode = extractDashboardScript(dashboardBladeSource);
    vm.runInContext(scriptCode, context);

    return {
        context,
        document,
        window: windowObj,
        checkboxEl,
        intervals,
        clearIntervalCalls,
        toastrCalls,
        ajaxCalls,
        createdMarkers,
        mapCalls,
        layerCalls,
        setNow(nextNow) {
            currentNowMs = new Date(nextNow).getTime();
        },
        setHideStalePinsChecked(checked) {
            assert.ok(checkboxEl, 'Expected #hideStalePinsCheckbox element in dashboard.blade.php');
            checkboxEl.checked = Boolean(checked);
            checkboxEl.dispatchEvent('change');
        },
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

        const twoDaysAgo = new Date(Date.now() - 2 * 24 * 60 * 60 * 1000);
        const oneDayAgo = new Date(Date.now() - 1 * 24 * 60 * 60 * 1000);
        const recentSql1 = formatLocalSqlTimestamp(twoDaysAgo);
        const recentSql2 = formatLocalSqlTimestamp(oneDayAgo);

        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Device 1', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: recentSql1 } },
                { id: 2, name: 'Device 2', code: 'Australia/Perth', position: { latitude: -33.86, longitude: 151.20, created_at: recentSql1 } }
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
                { id: 1, name: 'Device 1', code: 'Australia/Perth', position: { latitude: -31.96, longitude: 115.87, created_at: recentSql2 } }
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

    test('Hide stale pins checkbox is checked by default and hides >1-month pins on initial load while fitting bounds on visible pins', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-11-10T12:00:00.000Z' });
        assert.ok(harness.checkboxEl, 'Expected #hideStalePinsCheckbox in dashboard.blade.php');
        assert.strictEqual(harness.checkboxEl.checked, true, 'Checkbox must be checked by default');

        harness.triggerReady();
        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Recent 1', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-05T12:00:00.000000Z' } },
                { id: 2, name: 'Recent 2', code: 'Australia/Perth', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-10-15T12:00:00.000000Z' } },
                { id: 3, name: 'Stale Pin', code: 'Australia/Perth', position: { latitude: -37.81, longitude: 144.96, created_at: '2026-09-25T12:00:00.000000Z' } }
            ]
        });

        assert.ok(harness.context.markersById[1], 'Recent pin 1 must be visible');
        assert.ok(harness.context.markersById[2], 'Recent pin 2 must be visible');
        assert.strictEqual(harness.context.markersById[3], undefined, 'Pin older than 1 month must be hidden by default');
        assert.strictEqual(harness.layerCalls.added.length, 2);
        assert.strictEqual(harness.mapCalls.fitBounds.length, 1);
        assert.deepStrictEqual(
            Array.from(harness.mapCalls.fitBounds[0].bounds.latLngs, (pair) => Array.from(pair)),
            [
                [-31.95, 115.86],
                [-33.86, 151.20]
            ]
        );
    });

    test('Initial load when all pins are >1 month old centers map on defaultCenter/defaultZoom and shows zero markers', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-11-10T12:00:00.000Z' });

        harness.triggerReady();
        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 10, name: 'Very Old', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-09-01T12:00:00.000000Z' } },
                { id: 11, name: 'Also Old', code: 'Australia/Sydney', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-10-01T12:00:00.000000Z' } }
            ]
        });

        assert.deepStrictEqual(Object.keys(harness.context.markersById), []);
        assert.strictEqual(harness.layerCalls.added.length, 0);
        assert.strictEqual(harness.mapCalls.setView.length, 1);
        assert.deepStrictEqual(harness.mapCalls.setView[0], {
            center: harness.context.defaultCenter,
            zoom: harness.context.defaultZoom
        });
        assert.strictEqual(harness.mapCalls.fitBounds.length, 0);
        assert.strictEqual(harness.mapCalls.flyTo.length, 0);
        assert.strictEqual(harness.context.isInitialLoad, false);
    });

    test('Unchecking hide stale pins immediately reveals older pins without resetting viewport or waiting for poll', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-11-10T12:00:00.000Z' });

        harness.triggerReady();
        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Recent Pin', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-09T12:00:00.000000Z' } },
                { id: 2, name: 'Stale Pin', code: 'Australia/Sydney', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-10-01T12:00:00.000000Z' } }
            ]
        });

        assert.strictEqual(harness.mapCalls.flyTo.length, 1, 'Initial single visible pin uses flyTo');
        const recentMarker = harness.context.markersById[1];
        recentMarker.openPopup();
        assert.strictEqual(harness.context.markersById[2], undefined);

        // Uncheck immediately without issuing a new AJAX call
        harness.setHideStalePinsChecked(false);

        assert.strictEqual(harness.ajaxCalls.length, 1, 'Toggling checkbox must not trigger a new AJAX call');
        assert.strictEqual(harness.context.markersById[1], recentMarker, 'Existing marker instance must remain untouched');
        assert.strictEqual(recentMarker.isPopupOpen(), true, 'Open popup on recent pin must remain open');
        assert.ok(harness.context.markersById[2], 'Stale pin must immediately appear when unchecked');
        assert.strictEqual(harness.layerCalls.clearedCount, 0);
        assert.strictEqual(harness.mapCalls.flyTo.length, 1, 'Viewport must not reset on toggle');
        assert.strictEqual(harness.mapCalls.fitBounds.length, 0, 'Viewport must not re-fit on toggle');
        assert.strictEqual(harness.mapCalls.setView.length, 0, 'Viewport must not reset on toggle');
    });

    test('Re-checking hide stale pins immediately detaches older pins via removeLayer without clearLayers', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-11-10T12:00:00.000Z' });

        harness.triggerReady();
        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Recent Pin', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-09T12:00:00.000000Z' } },
                { id: 2, name: 'Stale Pin', code: 'Australia/Sydney', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-10-01T12:00:00.000000Z' } }
            ]
        });

        harness.setHideStalePinsChecked(false);
        const recentMarker = harness.context.markersById[1];
        const staleMarker = harness.context.markersById[2];
        assert.ok(staleMarker);
        recentMarker.openPopup();

        harness.setHideStalePinsChecked(true);

        assert.strictEqual(harness.ajaxCalls.length, 1);
        assert.strictEqual(harness.layerCalls.clearedCount, 0, 'Must never call clearLayers()');
        assert.strictEqual(harness.context.markersById[2], undefined, 'Stale pin must be deleted from markersById');
        assert.strictEqual(harness.layerCalls.removed.includes(staleMarker), true, 'Stale pin must be removed via removeLayer');
        assert.strictEqual(harness.context.markersById[1], recentMarker, 'Recent pin must remain intact');
        assert.strictEqual(recentMarker.isPopupOpen(), true);
    });

    test('Handles ISO-8601 UTC/offset and YYYY-MM-DD HH:mm(:ss) formats, month-end clamping, exact cutoff boundary, and invalid/missing timestamps safely', () => {
        // Use Aug 10 -> Jul 10 (no DST transitions globally) to test exact second/minute boundaries independently
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-08-10T12:00:00.000Z' });
        harness.triggerReady();

        const exactCutoffDate = new Date('2026-07-10T12:00:00.000Z');
        const oneMinuteOlderDate = new Date('2026-07-10T11:59:00.000Z');
        const boundaryLocalSql = formatLocalSqlTimestamp(exactCutoffDate);
        const oneMinuteOlderSql = formatLocalSqlTimestamp(oneMinuteOlderDate);

        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Exact ISO Boundary', position: { latitude: -31.1, longitude: 115.1, created_at: '2026-07-10T12:00:00.000000Z' } },
                { id: 2, name: '1s Older ISO', position: { latitude: -31.2, longitude: 115.2, created_at: '2026-07-10T11:59:59.000000Z' } },
                { id: 3, name: 'Exact Local SQL', position: { latitude: -31.3, longitude: 115.3, created_at: boundaryLocalSql } },
                { id: 4, name: '1m Older Local SQL', position: { latitude: -31.4, longitude: 115.4, created_at: oneMinuteOlderSql } },
                { id: 5, name: 'Offset ISO Recent', position: { latitude: -31.5, longitude: 115.5, created_at: '2026-07-10T19:00:01+07:00' } },
                { id: 6, name: 'Offset ISO Stale', position: { latitude: -31.6, longitude: 115.6, created_at: '2026-07-10T18:59:59+07:00' } },
                { id: 7, name: 'Null Timestamp', position: { latitude: -31.7, longitude: 115.7, created_at: null } },
                { id: 8, name: 'Empty Timestamp', position: { latitude: -31.8, longitude: 115.8, created_at: '   ' } },
                { id: 9, name: 'Malformed Timestamp', position: { latitude: -31.9, longitude: 115.9, created_at: 'not-a-date' } },
                { id: 10, name: 'Impossible Calendar Date', position: { latitude: -32.0, longitude: 116.0, created_at: '2026-02-30 10:00:00' } }
            ]
        });

        assert.ok(harness.context.markersById[1], 'Exact 1-month cutoff ISO pin remains visible');
        assert.strictEqual(harness.context.markersById[2], undefined, '1s older than 1 month ISO pin is hidden');
        assert.ok(harness.context.markersById[3], 'Exact 1-month cutoff local SQL pin remains visible');
        assert.strictEqual(harness.context.markersById[4], undefined, '1m older local SQL pin is hidden');
        assert.ok(harness.context.markersById[5], 'Timezone offset ISO pin newer than cutoff remains visible');
        assert.strictEqual(harness.context.markersById[6], undefined, 'Timezone offset ISO pin older than cutoff is hidden');
        assert.ok(harness.context.markersById[7], 'Null created_at safely falls back to visible');
        assert.ok(harness.context.markersById[8], 'Empty created_at safely falls back to visible');
        assert.ok(harness.context.markersById[9], 'Malformed created_at safely falls back to visible');
        assert.ok(harness.context.markersById[10], 'Impossible calendar date safely falls back to visible');

        // Test month-end clamping: March 31 -> Feb 28 (non-leap) and Feb 29 (leap) without overflowing to March 3
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-03-31T12:00:00.000Z' });
        harness.triggerReady();
        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 21, name: 'March 1 (Clamped Check)', position: { latitude: -31.1, longitude: 115.1, created_at: '2026-03-01T12:00:00Z' } },
                { id: 22, name: 'Feb 28 After Clamped Cutoff', position: { latitude: -31.2, longitude: 115.2, created_at: '2026-02-28T15:00:00Z' } },
                { id: 23, name: 'Feb 28 Before Clamped Cutoff', position: { latitude: -31.3, longitude: 115.3, created_at: '2026-02-28T09:00:00Z' } }
            ]
        });
        assert.ok(harness.context.markersById[21], 'March 1 must stay visible when March 31 clamps to Feb 28 (would wrongly hide if Date.setMonth overflowed to March 3)');
        assert.ok(harness.context.markersById[22], 'Feb 28 late afternoon remains visible under Feb 28 cutoff');
        assert.strictEqual(harness.context.markersById[23], undefined, 'Before clamped Feb 28 cutoff is hidden');

        harness.cleanup();
        harness = createDashboardHarness({ now: '2024-03-31T12:00:00.000Z' });
        harness.triggerReady();
        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 31, name: 'Leap Feb 29 After Cutoff', position: { latitude: -31.1, longitude: 115.1, created_at: '2024-02-29T15:00:00Z' } },
                { id: 32, name: 'Leap Feb 29 Before Cutoff', position: { latitude: -31.2, longitude: 115.2, created_at: '2024-02-29T09:00:00Z' } }
            ]
        });
        assert.ok(harness.context.markersById[31], 'Leap-year March 31 minus 1 month clamps to Feb 29');
        assert.strictEqual(harness.context.markersById[32], undefined);
    });

    test('Toggling checkbox while initial load is in-flight preserves isInitialLoad and applies toggled state when data arrives', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-11-10T12:00:00.000Z' });

        harness.triggerReady();
        assert.strictEqual(harness.context.isInitialLoad, true);

        // User unchecks before first AJAX response arrives
        harness.setHideStalePinsChecked(false);
        assert.strictEqual(harness.context.isInitialLoad, true, 'Premature toggle before data load must not consume isInitialLoad');
        assert.strictEqual(harness.mapCalls.setView.length, 0);

        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Recent', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-09T12:00:00Z' } },
                { id: 2, name: 'Stale', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-09-01T12:00:00Z' } }
            ]
        });

        assert.ok(harness.context.markersById[1]);
        assert.ok(harness.context.markersById[2], 'Unchecked state must be respected when initial response arrives');
        assert.strictEqual(harness.mapCalls.fitBounds.length, 1);
        assert.strictEqual(harness.context.isInitialLoad, false);
    });

    test('Preserves checkbox state across 10-second polling, visibilitychange resume, and manual refreshMarkers, and detaches pins that age past 1 month', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-11-10T12:00:00.000Z' });

        harness.triggerReady();
        const cutoffBeforeAdvance = harness.context.getOneMonthCutoffDate(new harness.context.Date());
        const aboutToAgeOutCreatedAt = new Date(cutoffBeforeAdvance.getTime() + 30_000).toISOString();
        const payload = [
            { id: 1, name: 'Always Recent', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-09T12:00:00Z' } },
            { id: 2, name: 'About To Age Out', position: { latitude: -32.00, longitude: 115.90, created_at: aboutToAgeOutCreatedAt } },
            { id: 3, name: 'Already Stale', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-09-01T12:00:00Z' } }
        ];

        harness.ajaxCalls[0].resolveSuccess({ data: payload });
        assert.ok(harness.context.markersById[1]);
        assert.ok(harness.context.markersById[2]);
        assert.strictEqual(harness.context.markersById[3], undefined);

        // Advance clock by 1 minute so id=2 ages past 1 month on next poll while checkbox stays checked
        harness.setNow('2026-11-10T12:01:00.000Z');
        harness.tickInterval();
        harness.ajaxCalls[1].resolveSuccess({ data: payload });

        assert.strictEqual(harness.checkboxEl.checked, true);
        assert.ok(harness.context.markersById[1]);
        assert.strictEqual(harness.context.markersById[2], undefined, 'Pin aging past 1 month must be detached on next poll when checked');
        assert.strictEqual(harness.context.markersById[3], undefined);
        assert.strictEqual(harness.mapCalls.fitBounds.length, 1, 'Viewport must stay preserved across polls');

        // Uncheck, then verify visibilitychange resume & manual refreshMarkers preserve unchecked state
        harness.setHideStalePinsChecked(false);
        assert.ok(harness.context.markersById[2]);
        assert.ok(harness.context.markersById[3]);

        harness.document.dispatchVisibilityChange(true);
        harness.document.dispatchVisibilityChange(false);
        assert.strictEqual(harness.ajaxCalls.length, 3);
        harness.ajaxCalls[2].resolveSuccess({ data: payload });

        assert.strictEqual(harness.checkboxEl.checked, false);
        assert.ok(harness.context.markersById[1]);
        assert.ok(harness.context.markersById[2]);
        assert.ok(harness.context.markersById[3]);

        harness.context.refreshMarkers();
        assert.strictEqual(harness.ajaxCalls.length, 4);
        harness.ajaxCalls[3].resolveSuccess({ data: payload });

        assert.strictEqual(harness.checkboxEl.checked, false);
        assert.ok(harness.context.markersById[1]);
        assert.ok(harness.context.markersById[2]);
        assert.ok(harness.context.markersById[3]);

        // Re-check and verify manual refresh keeps checked state and hides stale pins
        harness.setHideStalePinsChecked(true);
        harness.context.refreshMarkers();
        assert.strictEqual(harness.ajaxCalls.length, 5);
        harness.ajaxCalls[4].resolveSuccess({ data: payload });

        assert.strictEqual(harness.checkboxEl.checked, true);
        assert.ok(harness.context.markersById[1]);
        assert.strictEqual(harness.context.markersById[2], undefined);
        assert.strictEqual(harness.context.markersById[3], undefined);
    });

    test('Real bundled moment and moment-timezone render popups cleanly, handle +HH short offsets, and match 1-month cutoff semantics', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-01-31T12:00:00.000Z', useRealMoment: true });
        harness.triggerReady();

        const getPopupText = (marker) => marker.getPopup().getContent().children
            .map((child) => (child.textContent !== undefined ? child.textContent : '\n'))
            .join('');

        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: 1, name: 'Recent ISO', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-01-15T12:00:00.000000Z' } },
                { id: 2, name: 'Year Rollover Cutoff Exact', code: 'Australia/Perth', position: { latitude: -31.96, longitude: 115.87, created_at: '2025-12-31T12:00:00.000000Z' } },
                { id: 3, name: 'Year Rollover Stale', code: 'Australia/Perth', position: { latitude: -31.97, longitude: 115.88, created_at: '2025-12-31T11:59:59.000000Z' } },
                { id: 4, name: 'Short Offset Stale', code: 'Australia/Perth', position: { latitude: -31.98, longitude: 115.89, created_at: '2025-12-30T12:00:00+07' } },
                { id: 5, name: 'Invalid Fallback', code: 'Australia/Perth', position: { latitude: -31.99, longitude: 115.90, created_at: '2026-02-30 10:00:00' } }
            ]
        });

        assert.ok(harness.context.markersById[1]);
        assert.strictEqual(getPopupText(harness.context.markersById[1]), 'Recent ISO\n15 Jan 2026 20:00 AWST');
        assert.ok(harness.context.markersById[2], 'Jan 31 minus 1 month clamps to Dec 31 of previous year');
        assert.strictEqual(harness.context.markersById[3], undefined, '1s before Dec 31 cutoff is hidden');
        assert.strictEqual(harness.context.markersById[4], undefined, 'Short +07 offset older than 1 month is parsed and hidden');
        assert.ok(harness.context.markersById[5], 'Invalid calendar date falls back to visible');
        assert.strictEqual(getPopupText(harness.context.markersById[5]), 'Invalid Fallback\n', 'Invalid timestamp renders empty string instead of Invalid date');

        // Verify parity with real moment().subtract(1, 'months')
        const realMomentCutoff = harness.context.moment().subtract(1, 'months').valueOf();
        const helperCutoff = harness.context.getOneMonthCutoffDate(new harness.context.Date()).getTime();
        assert.strictEqual(helperCutoff, realMomentCutoff);

        // Unchecking reveals short-offset stale pin with formatted real moment-timezone popup
        harness.setHideStalePinsChecked(false);
        assert.ok(harness.context.markersById[4]);
        assert.strictEqual(getPopupText(harness.context.markersById[4]), 'Short Offset Stale\n30 Dec 2025 13:00 AWST');
    });

    test('Hidden stale pins receive latest coordinates/timestamp when polled and reveal fresh updates on uncheck or recovery', () => {
        harness.cleanup();
        harness = createDashboardHarness({ now: '2026-11-10T12:00:00.000Z', useRealMoment: true });
        harness.triggerReady();

        const getPopupText = (marker) => marker.getPopup().getContent().children
            .map((child) => (child.textContent !== undefined ? child.textContent : '\n'))
            .join('');

        harness.ajaxCalls[0].resolveSuccess({
            data: [
                { id: null, name: 'Zero Pos ID Recent', code: 'Australia/Perth', position: { id: 0, latitude: 0, longitude: 0, created_at: '2026-11-09T12:00:00Z' } },
                { id: 2, name: 'Movable Stale', code: 'Australia/Perth', position: { latitude: -33.0, longitude: 151.0, created_at: '2026-09-15T12:00:00Z' } },
                { id: 3, name: 'Recovering Pin', code: 'Australia/Perth', position: { latitude: -31.9, longitude: 115.8, created_at: '2026-09-01T12:00:00Z' } }
            ]
        });

        assert.ok(harness.context.markersById[0], 'position.id=0 fallback and [0,0] coordinates must render');
        assert.strictEqual(harness.context.markersById[2], undefined);
        assert.strictEqual(harness.context.markersById[3], undefined);

        // Poll 2 updates hidden pin id=2 coordinates while still stale, and pin id=3 receives a fresh ping while checked
        harness.tickInterval();
        harness.ajaxCalls[1].resolveSuccess({
            data: [
                { id: null, name: 'Zero Pos ID Recent', code: 'Australia/Perth', position: { id: 0, latitude: 0, longitude: 0, created_at: '2026-11-09T12:00:00Z' } },
                { id: 2, name: 'Movable Stale', code: 'Australia/Perth', position: { latitude: -34.5, longitude: 150.5, created_at: '2026-10-01T12:00:00Z' } },
                { id: 3, name: 'Recovering Pin', code: 'Australia/Perth', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-10T10:00:00Z' } }
            ]
        });

        assert.ok(harness.context.markersById[0]);
        assert.strictEqual(harness.context.markersById[2], undefined, 'Updated but still stale pin remains hidden while checked');
        assert.ok(harness.context.markersById[3], 'Pin receiving a fresh GPS ping appears immediately even while checked');
        assert.strictEqual(harness.mapCalls.fitBounds.length, 0, 'Viewport must stay preserved');
        assert.strictEqual(harness.mapCalls.flyTo.length, 1, 'Initial single visible pin flyTo must not re-fire on poll');

        // Unchecking reveals pin id=2 with the latest coordinates and popup timestamp from Poll 2
        harness.setHideStalePinsChecked(false);
        assert.ok(harness.context.markersById[2]);
        assert.deepStrictEqual(harness.context.markersById[2].getLatLng(), { lat: -34.5, lng: 150.5 });
        assert.strictEqual(getPopupText(harness.context.markersById[2]), 'Movable Stale\n01 Oct 2026 20:00 AWST');
    });

    test('DST spring-forward local SQL timestamps remain valid and obey 1-month filtering', () => {
        const script = `
            const assert = require('node:assert');
            const fs = require('node:fs');
            const vm = require('node:vm');
            const blade = fs.readFileSync(${JSON.stringify(dashboardBladePath)}, 'utf8');
            const scripts = [...blade.matchAll(/<script>([\\s\\S]*?)<\\/script>/g)];
            const code = scripts[scripts.length - 1][1].replace(/\\{\\{[^}]+\\}\\}/g, '/dashboard/markers');
            const ctx = vm.createContext({
                Array, Date, Number, Object, String, Boolean,
                document: { hidden: false, getElementById: () => ({ checked: true }), createElement: (t) => ({ tagName: t, children: [], appendChild(c){this.children.push(c); return c;} }), createTextNode: (t) => ({ textContent: String(t) }), addEventListener(){} },
                window: { addEventListener(){} },
                setInterval: () => 1, clearInterval: () => {},
                toastr: { error(){}, success(){} },
                L: { tileLayer: () => ({}), map: () => ({ setView(){}, flyTo(){}, fitBounds(){}, hasLayer:()=>true }), control: { layers: () => ({ addTo: () => ({}) }) }, LayerGroup: function(){ return { addTo(){return this;}, addLayer(){}, removeLayer(){} }; }, marker: () => { const m = { _p: null, getLatLng:()=>({lat:0,lng:0}), bindPopup(p){m._p=p; return m;}, setPopupContent(p){m._p=p; return m;} }; return m; }, latLngBounds: () => ({}) },
                ['$']: Object.assign(() => ({ ready(){}, on(){}, is: () => true }), { each: (a, f) => a.forEach((v, i) => f(i, v)), ajax: () => ({}) })
            });
            vm.runInContext(fs.readFileSync(${JSON.stringify(momentMinPath)}, 'utf8'), ctx);
            vm.runInContext(fs.readFileSync(${JSON.stringify(momentTzMinPath)}, 'utf8'), ctx);
            vm.runInContext(code, ctx);
            const parsed = ctx.parseMarkerTimestamp('2026-10-04 02:30:00');
            assert.ok(parsed instanceof ctx.Date, 'Sydney DST gap local SQL timestamp must parse instead of returning null');
            assert.strictEqual(
                ctx.isPositionOlderThanOneMonth('2026-10-04 02:30:00', new ctx.Date('2026-11-10T12:00:00Z')),
                true,
                '37-day-old DST gap SQL timestamp must be treated as older than 1 month'
            );
            assert.strictEqual(
                ctx.isPositionOlderThanOneMonth('2026-10-04 02:30:00', new ctx.Date('2026-10-20T12:00:00Z')),
                false,
                '16-day-old DST gap SQL timestamp must remain visible'
            );
        `;
        const result = spawnSync(process.execPath, ['-e', script], {
            env: { ...process.env, TZ: 'Australia/Sydney' },
            encoding: 'utf8'
        });
        assert.strictEqual(result.status, 0, result.stderr || result.stdout);
    });
});
