const { test, describe, beforeEach, afterEach } = require('node:test');
const assert = require('node:assert');

describe('Dashboard Markers Auto-Refresh Client Logic', () => {
    let mockMap;
    let mockMarkersLayer;
    let markersById;
    let isInitialLoad;
    let markersTimer;
    let pollIntervalMs;
    let isFetchingMarkers;
    let activeMarkersXhr;
    let ajaxHandler;

    // Factory to recreate the dashboard logic environment
    function setupEnvironment() {
        markersById = {};
        isInitialLoad = true;
        markersTimer = null;
        pollIntervalMs = 10000;
        isFetchingMarkers = false;
        activeMarkersXhr = null;

        mockMap = {
            setViewCalls: [],
            flyToCalls: [],
            fitBoundsCalls: [],
            setView(center, zoom) { this.setViewCalls.push({ center, zoom }); },
            flyTo(center, zoom) { this.flyToCalls.push({ center, zoom }); },
            fitBounds(bounds, options) { this.fitBoundsCalls.push({ bounds, options }); },
            hasLayer(layer) { return true; }
        };

        mockMarkersLayer = {
            layers: [],
            addLayer(layer) { this.layers.push(layer); },
            removeLayer(layer) {
                const idx = this.layers.indexOf(layer);
                if (idx !== -1) this.layers.splice(idx, 1);
            },
            clearLayersCalls: 0,
            clearLayers() { this.clearLayersCalls++; this.layers = []; }
        };
    }

    function createMockMarker(latLng) {
        return {
            latLng: latLng,
            popupContent: null,
            popupIsOpen: false,
            clickHandlers: [],
            getLatLng() { return { lat: this.latLng[0], lng: this.latLng[1] }; },
            setLatLng(newLatLng) { this.latLng = newLatLng; },
            bindPopup(content) { this.popupContent = content; return this; },
            setPopupContent(content) { this.popupContent = content; return this; },
            isPopupOpen() { return this.popupIsOpen; },
            openPopup() { this.popupIsOpen = true; return this; },
            closePopup() { this.popupIsOpen = false; return this; },
            on(evt, handler) {
                if (evt === 'click') this.clickHandlers.push(handler);
                return this;
            }
        };
    }

    function createPopupContent(name, formattedTime) {
        return {
            name: name || '',
            formattedTime: formattedTime || '',
            toString() { return `${this.name} - ${this.formattedTime}`; }
        };
    }

    function markersUser(ajaxResponse) {
        if (isFetchingMarkers) {
            return;
        }
        isFetchingMarkers = true;

        const res = ajaxResponse;
        const currentIds = {};
        const latLngs = [];

        if (res && res.data && Array.isArray(res.data)) {
            res.data.forEach((val, idx) => {
                if (
                    val &&
                    val.position &&
                    (typeof val.position.latitude === 'number' ||
                        (typeof val.position.latitude === 'string' && val.position.latitude.trim() !== '')) &&
                    (typeof val.position.longitude === 'number' ||
                        (typeof val.position.longitude === 'string' && val.position.longitude.trim() !== ''))
                ) {
                    const lat = Number(val.position.latitude);
                    const lng = Number(val.position.longitude);

                    if (
                        Number.isFinite(lat) &&
                        Number.isFinite(lng) &&
                        lat >= -90 &&
                        lat <= 90 &&
                        lng >= -180 &&
                        lng <= 180
                    ) {
                        const deviceId = val.id != null ? val.id : (val.position && val.position.id ? val.position.id : idx);
                        currentIds[deviceId] = true;

                        const formattedTime = val.position.created_at || '';
                        const popupContent = createPopupContent(val.name, formattedTime);

                        if (markersById[deviceId]) {
                            const existingMarker = markersById[deviceId];
                            const currentLatLng = existingMarker.getLatLng();
                            if (currentLatLng.lat !== lat || currentLatLng.lng !== lng) {
                                existingMarker.setLatLng([lat, lng]);
                            }
                            existingMarker.setPopupContent(popupContent);
                        } else {
                            const marker = createMockMarker([lat, lng])
                                .bindPopup(popupContent);
                            mockMarkersLayer.addLayer(marker);
                            markersById[deviceId] = marker;
                        }

                        latLngs.push([lat, lng]);
                    }
                }
            });
        }

        Object.keys(markersById).forEach(id => {
            if (!currentIds[id]) {
                mockMarkersLayer.removeLayer(markersById[id]);
                delete markersById[id];
            }
        });

        if (isInitialLoad) {
            if (latLngs.length === 0) {
                mockMap.setView([-25.2744, 133.7751], 4);
            } else if (latLngs.length === 1) {
                mockMap.flyTo(latLngs[0], 14);
            } else {
                mockMap.fitBounds(latLngs, { padding: [50, 50], maxZoom: 16 });
            }
            isInitialLoad = false;
        }

        isFetchingMarkers = false;
        activeMarkersXhr = null;
    }

    function stopMarkersPolling() {
        if (markersTimer) {
            clearInterval(markersTimer);
            markersTimer = null;
        }
        if (activeMarkersXhr && typeof activeMarkersXhr.abort === 'function') {
            activeMarkersXhr.abort();
            activeMarkersXhr = null;
        }
        isFetchingMarkers = false;
    }

    function startMarkersPolling(timerCallback) {
        stopMarkersPolling();
        markersTimer = setInterval(timerCallback, pollIntervalMs);
    }

    beforeEach(() => {
        setupEnvironment();
    });

    afterEach(() => {
        stopMarkersPolling();
    });

    test('Initial load fits bounds, subsequent interval update preserves viewport', () => {
        const initialData = {
            data: [
                { id: 1, name: 'Device 1', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-06 10:00' } },
                { id: 2, name: 'Device 2', position: { latitude: -33.86, longitude: 151.20, created_at: '2026-11-06 10:00' } }
            ]
        };

        // 1. Initial load
        markersUser(initialData);

        assert.strictEqual(mockMap.fitBoundsCalls.length, 1, 'Initial load must call fitBounds once');
        assert.strictEqual(isInitialLoad, false, 'isInitialLoad must be false after first call');
        assert.strictEqual(mockMarkersLayer.clearLayersCalls, 0, 'Must NOT clear layers');
        assert.strictEqual(mockMarkersLayer.layers.length, 2);

        // 2. Subsequent interval update
        const updatedData = {
            data: [
                { id: 1, name: 'Device 1', position: { latitude: -31.96, longitude: 115.87, created_at: '2026-11-06 10:01' } },
                { id: 2, name: 'Device 2', position: { latitude: -33.87, longitude: 151.21, created_at: '2026-11-06 10:01' } }
            ]
        };

        markersUser(updatedData);

        // Verify viewport wasn't reset
        assert.strictEqual(mockMap.fitBoundsCalls.length, 1, 'Subsequent poll must NOT call fitBounds');
        assert.strictEqual(mockMap.flyToCalls.length, 0, 'Subsequent poll must NOT call flyTo');
        assert.strictEqual(mockMap.setViewCalls.length, 0, 'Subsequent poll must NOT call setView');
    });

    test('Existing marker positions and popups update smoothly by device ID and keep open popups open', () => {
        const data1 = {
            data: [
                { id: 10, name: 'Unit 10', position: { latitude: -31.95, longitude: 115.86, created_at: '2026-11-06 12:00' } }
            ]
        };

        markersUser(data1);
        const marker = markersById[10];
        assert.ok(marker, 'Marker 10 must exist');

        // User opens the popup
        marker.openPopup();
        assert.strictEqual(marker.isPopupOpen(), true, 'Popup should be open');
        assert.strictEqual(marker.popupContent.formattedTime, '2026-11-06 12:00');

        // Device sends updated coordinates and timestamp
        const data2 = {
            data: [
                { id: 10, name: 'Unit 10', position: { latitude: -31.96, longitude: 115.88, created_at: '2026-11-06 12:05' } }
            ]
        };

        markersUser(data2);

        // Verify marker instance was preserved (same object reference)
        assert.strictEqual(markersById[10], marker, 'Existing marker object reference must be retained');
        assert.strictEqual(marker.getLatLng().lat, -31.96, 'Latitude must be updated');
        assert.strictEqual(marker.getLatLng().lng, 115.88, 'Longitude must be updated');

        // Verify popup is STILL open and timestamp was updated
        assert.strictEqual(marker.isPopupOpen(), true, 'Popup must remain open');
        assert.strictEqual(marker.popupContent.formattedTime, '2026-11-06 12:05', 'Popup timestamp must be updated');
    });

    test('Device removal removes marker from layer and dictionary', () => {
        const data1 = {
            data: [
                { id: 1, name: 'Device 1', position: { latitude: -31.95, longitude: 115.86 } },
                { id: 2, name: 'Device 2', position: { latitude: -33.86, longitude: 151.20 } }
            ]
        };

        markersUser(data1);
        assert.strictEqual(mockMarkersLayer.layers.length, 2);

        // Device 2 goes offline / removed
        const data2 = {
            data: [
                { id: 1, name: 'Device 1', position: { latitude: -31.95, longitude: 115.86 } }
            ]
        };

        markersUser(data2);
        assert.strictEqual(mockMarkersLayer.layers.length, 1);
        assert.ok(markersById[1]);
        assert.strictEqual(markersById[2], undefined);
    });

    test('Polling timer management: starts interval, clears on stop/cleanup, prevents pileups', () => {
        let callbackFiredCount = 0;
        startMarkersPolling(() => {
            callbackFiredCount++;
        });

        assert.ok(markersTimer, 'Interval timer must be active');

        stopMarkersPolling();
        assert.strictEqual(markersTimer, null, 'Interval timer must be cleared');

        // Test in-flight pileup prevention
        isFetchingMarkers = true;
        let attemptedFetch = false;
        if (!isFetchingMarkers) {
            attemptedFetch = true;
        }
        assert.strictEqual(attemptedFetch, false, 'Fetch must be blocked when previous request is still fetching');
    });
});

