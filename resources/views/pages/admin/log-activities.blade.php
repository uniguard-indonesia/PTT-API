@extends('layouts.default')
@section('title')
    {{ $title }}
@endsection
@push('css')
    <link href="{{ asset('assets/plugins/datatables.net-bs5/css/dataTables.bootstrap5.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/plugins/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css') }}"
        rel="stylesheet" />
    <link href="{{ asset('assets/plugins/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css') }}" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/plugins/toastr/css/toastr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/sweetalert2/dist/sweetalert2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/bootstrap-select/dist/css/bootstrap-select.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/leaflet/leaflet.css') }}">
    <style>
        .js-view-location {
            font-size: inherit;
            line-height: inherit;
            text-transform: none;
            vertical-align: baseline;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
        .js-view-location:hover,
        .js-view-location:focus {
            text-decoration: underline;
        }
    </style>
@endpush
@push('scripts')
    <script src="{{ asset('assets/plugins/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-bs5/js/dataTables.bootstrap5.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-buttons/js/dataTables.buttons.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-buttons-bs5/js/buttons.bootstrap5.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-buttons/js/buttons.colVis.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-buttons/js/buttons.flash.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-buttons/js/buttons.html5.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-buttons/js/buttons.print.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/jszip/dist/jszip.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/pdfmake/build/pdfmake.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/pdfmake/build/vfs_fonts.js') }}"></script>
    <script src="{{ asset('assets/plugins/sweetalert2/dist/sweetalert2.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/toastr/js/toastr.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/bootstrap-select/dist/js/bootstrap-select.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/leaflet/leaflet.js') }}"></script>
    <script>
        let url = '';
        let method = '';
        let activityMap = null;
        let activityPinLayer = null;
        let temporaryMarker = null;
        let pendingLocation = null;
        let pendingReopenLocation = null;
        let isLocationModalShown = false;
        let isLocationModalClosing = false;
        let exportInFlight = false;
        const logActivityAjaxUrl = "{{ route('admin.log-activity.index') }}";
        const EXPORT_COLUMN_TITLES = ['CREATED AT', 'COMPANY', 'NAME', 'ACTIVITY', 'ATTACHMENT'];

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        function parseStrictNumber(value) {
            if (typeof value === 'number') {
                return Number.isFinite(value) ? value : null;
            }
            if (typeof value === 'string') {
                const trimmed = value.trim();
                if (trimmed === '' || !/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?$/.test(trimmed)) {
                    return null;
                }
                const parsed = Number(trimmed);
                return Number.isFinite(parsed) ? parsed : null;
            }
            return null;
        }

        function parseValidCoordinates(latValue, lngValue) {
            const lat = parseStrictNumber(latValue);
            const lng = parseStrictNumber(lngValue);
            if (lat === null || lng === null) {
                return null;
            }
            if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
                return null;
            }
            return {
                lat: lat === 0 ? 0 : lat,
                lng: lng === 0 ? 0 : lng
            };
        }

        function parseLocationActivity(activityText, row) {
            const rawText = (activityText === null || activityText === undefined) ? '' : String(activityText);
            const hasExplicitRowCoords = Boolean(
                row && (
                    (row.latitude !== undefined && row.latitude !== null) ||
                    (row.longitude !== undefined && row.longitude !== null)
                )
            );
            const rowCoords = row
                ? parseValidCoordinates(row.latitude, row.longitude)
                : null;

            const match = rawText.match(
                /^\s*Updating Location\s*\(\s*([+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)\s*,\s*([+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)\s*\)\s*$/
            );

            if (match) {
                const parsedFromText = parseValidCoordinates(match[1], match[2]);
                if (parsedFromText) {
                    if (hasExplicitRowCoords && !rowCoords) {
                        return {
                            displayText: 'Updating Location',
                            coords: null
                        };
                    }
                    return {
                        displayText: 'Updating Location',
                        coords: rowCoords || parsedFromText
                    };
                }
            }

            if (rowCoords && rawText.trim() === 'Updating Location') {
                return {
                    displayText: 'Updating Location',
                    coords: rowCoords
                };
            }

            return {
                displayText: rawText,
                coords: null
            };
        }

        function createLocationPopupContent(userName, formattedTime, label) {
            if (typeof document !== 'undefined' && typeof document.createElement === 'function') {
                const container = document.createElement('div');
                const titleEl = document.createElement('strong');
                titleEl.appendChild(document.createTextNode(label || 'Updating Location'));
                container.appendChild(titleEl);

                if (userName) {
                    container.appendChild(document.createElement('br'));
                    container.appendChild(document.createTextNode(String(userName)));
                }

                if (formattedTime) {
                    container.appendChild(document.createElement('br'));
                    container.appendChild(document.createTextNode(String(formattedTime)));
                }

                return container;
            }

            const parts = ['<strong>' + escapeHtml(label || 'Updating Location') + '</strong>'];
            if (userName) {
                parts.push(escapeHtml(userName));
            }
            if (formattedTime) {
                parts.push(escapeHtml(formattedTime));
            }
            return parts.join('<br>');
        }

        function ensureActivityMap(initialCoords) {
            if (activityMap) {
                return activityMap;
            }
            if (typeof L === 'undefined') {
                return null;
            }

            const googleTile = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}&hl=en', {
                maxZoom: 20,
                subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                attribution: ''
            });
            const osmTile = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            });
            const googleTerrain = L.tileLayer('https://{s}.google.com/vt?lyrs=p&x={x}&y={y}&z={z}&hl=en', {
                maxZoom: 20,
                subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
            });
            const googleSat = L.tileLayer('https://{s}.google.com/vt?lyrs=s&x={x}&y={y}&z={z}&hl=en', {
                maxZoom: 20,
                subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
            });
            const baseMaps = {
                'OpenStreetMap': osmTile,
                'Google Satellites': googleTile,
                'Google Terrain': googleTerrain,
                'Google Satellite Only': googleSat
            };

            const center = initialCoords ? [initialCoords.lat, initialCoords.lng] : [0, 0];
            const zoom = initialCoords ? 16 : 4;

            activityMap = L.map('activityLocationMap', {
                center: center,
                zoom: zoom,
                layers: [googleTile]
            });
            if (L.control && typeof L.control.layers === 'function') {
                L.control.layers(baseMaps).addTo(activityMap);
            }
            activityPinLayer = new L.LayerGroup().addTo(activityMap);

            return activityMap;
        }

        function clearTemporaryPin() {
            if (temporaryMarker && typeof temporaryMarker.closePopup === 'function') {
                temporaryMarker.closePopup();
            }
            if (activityMap && typeof activityMap.closePopup === 'function') {
                activityMap.closePopup();
            }
            if (activityPinLayer) {
                if (temporaryMarker && typeof activityPinLayer.removeLayer === 'function') {
                    activityPinLayer.removeLayer(temporaryMarker);
                }
                if (typeof activityPinLayer.clearLayers === 'function') {
                    activityPinLayer.clearLayers();
                }
            } else if (temporaryMarker && typeof temporaryMarker.remove === 'function') {
                temporaryMarker.remove();
            }
            temporaryMarker = null;
        }

        function renderTemporaryPin(locationData) {
            if (!locationData) {
                return false;
            }
            const coords = parseValidCoordinates(locationData.latitude, locationData.longitude);
            if (!coords) {
                return false;
            }

            const map = ensureActivityMap(coords);
            if (!map) {
                return false;
            }

            if (activityPinLayer && typeof map.hasLayer === 'function' && !map.hasLayer(activityPinLayer)) {
                activityPinLayer.addTo(map);
            }

            clearTemporaryPin();

            const popupContent = createLocationPopupContent(
                locationData.name || '',
                locationData.created_at || '',
                locationData.label || 'Updating Location'
            );

            temporaryMarker = L.marker([coords.lat, coords.lng]).bindPopup(popupContent);
            if (activityPinLayer) {
                activityPinLayer.addLayer(temporaryMarker);
            } else {
                temporaryMarker.addTo(map);
            }

            map.setView([coords.lat, coords.lng], 16, { animate: false });
            if (typeof temporaryMarker.openPopup === 'function') {
                temporaryMarker.openPopup();
            }

            return true;
        }

        function openLocationMapModal(locationData) {
            if (!locationData) {
                return false;
            }
            const coords = parseValidCoordinates(locationData.latitude, locationData.longitude);
            if (!coords) {
                return false;
            }

            const userText = locationData.name ? String(locationData.name) : '';
            const timeText = locationData.created_at ? String(locationData.created_at) : '';
            const labelText = locationData.label ? String(locationData.label) : 'Updating Location';

            pendingLocation = {
                latitude: coords.lat,
                longitude: coords.lng,
                name: userText,
                created_at: timeText,
                label: labelText
            };

            const metaParts = [];
            if (userText) {
                metaParts.push('User: ' + userText);
            }
            if (timeText) {
                metaParts.push('Time: ' + timeText);
            }
            metaParts.push('Coordinates: ' + coords.lat.toFixed(6) + ', ' + coords.lng.toFixed(6));

            const $meta = $('#locationMapMeta');
            if ($meta && typeof $meta.text === 'function') {
                $meta.text(metaParts.join(' | '));
            }

            clearTemporaryPin();

            if (isLocationModalShown) {
                const map = ensureActivityMap(coords);
                if (map && typeof map.invalidateSize === 'function') {
                    map.invalidateSize();
                }
                renderTemporaryPin(pendingLocation);
            } else if (isLocationModalClosing) {
                pendingReopenLocation = pendingLocation;
                const $modal = $('#locationMapModal');
                if ($modal && typeof $modal.off === 'function' && typeof $modal.one === 'function') {
                    $modal.off('hidden.bs.modal.reopenLocationMap').one('hidden.bs.modal.reopenLocationMap', function() {
                        if (pendingReopenLocation) {
                            const nextLocation = pendingReopenLocation;
                            pendingReopenLocation = null;
                            openLocationMapModal(nextLocation);
                        }
                    });
                }
            } else {
                pendingReopenLocation = null;
                const $modal = $('#locationMapModal');
                if ($modal && typeof $modal.modal === 'function') {
                    $modal.modal('show');
                }
            }

            return true;
        }

        const logActivityColumns = [{
                data: 'created_at',
                name: 'created_at',
                render: function(data, type) {
                    const plain = decodeHtmlEntities(data);
                    if (type === 'display' || type === 'export') {
                        return escapeHtml(plain);
                    }
                    return plain;
                }
            },
            {
                data: 'company',
                name: 'company',
                orderable: false,
                searchable: true,
                render: function(data, type) {
                    const plain = decodeHtmlEntities(data);
                    if (type === 'display' || type === 'export') {
                        return escapeHtml(plain);
                    }
                    return plain;
                }
            },
            {
                data: 'name',
                name: 'name',
                orderable: false,
                searchable: true,
                render: function(data, type) {
                    const plain = decodeHtmlEntities(data);
                    if (type === 'display' || type === 'export') {
                        return escapeHtml(plain);
                    }
                    return plain;
                }
            },
            {
                data: 'activity',
                name: 'activity',
                render: function(data, type, row) {
                    const parsed = parseLocationActivity(data, row);
                    const plainDisplay = decodeHtmlEntities(parsed.displayText);
                    if (type === 'export') {
                        return escapeHtml(plainDisplay);
                    }
                    if (type !== 'display') {
                        return plainDisplay;
                    }
                    if (!parsed.coords) {
                        return escapeHtml(plainDisplay);
                    }

                    const userName = decodeHtmlEntities(row && row.name !== undefined && row.name !== null ? row.name : '');
                    const formattedTime = decodeHtmlEntities(row && row.created_at !== undefined && row.created_at !== null ? row.created_at : '');
                    const latStr = String(parsed.coords.lat);
                    const lngStr = String(parsed.coords.lng);

                    if (typeof document !== 'undefined' && typeof document.createElement === 'function') {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn btn-link btn-sm p-0 align-baseline js-view-location';
                        btn.setAttribute('data-lat', latStr);
                        btn.setAttribute('data-lng', lngStr);
                        btn.setAttribute('data-user', userName);
                        btn.setAttribute('data-timestamp', formattedTime);
                        btn.setAttribute('data-label', plainDisplay);
                        btn.setAttribute('title', 'View location on map');

                        const icon = document.createElement('i');
                        icon.className = 'fa fa-map-marker text-danger mr-1';
                        icon.setAttribute('aria-hidden', 'true');
                        btn.appendChild(icon);
                        btn.appendChild(document.createTextNode(plainDisplay));
                        return btn.outerHTML;
                    }

                    return '<button type="button" class="btn btn-link btn-sm p-0 align-baseline js-view-location"'
                        + ' data-lat="' + escapeHtml(latStr) + '"'
                        + ' data-lng="' + escapeHtml(lngStr) + '"'
                        + ' data-user="' + escapeHtml(userName) + '"'
                        + ' data-timestamp="' + escapeHtml(formattedTime) + '"'
                        + ' data-label="' + escapeHtml(plainDisplay) + '"'
                        + ' title="View location on map">'
                        + '<i class="fa fa-map-marker text-danger mr-1" aria-hidden="true"></i>'
                        + escapeHtml(plainDisplay)
                        + '</button>';
                }
            },
            {
                data: 'attachment',
                name: 'attachment',
                orderable: false,
                searchable: false,
                render: function(data, type, row, meta) {
                    if (type !== 'display') {
                        return data ?? '';
                    }
                    if (typeof data !== 'string' || data.trim() === '') {
                        return '';
                    }
                    const ext = data.trim().split('.').pop().toLowerCase();
                    const mimeByExt = {
                        mp3: 'audio/mpeg',
                        wav: 'audio/wav',
                        ogg: 'audio/ogg',
                        m4a: 'audio/mp4',
                        aac: 'audio/aac'
                    };
                    if (!Object.prototype.hasOwnProperty.call(mimeByExt, ext)) {
                        return '';
                    }
                    const rawId = row && row.id !== undefined && row.id !== null ? String(row.id).trim() : '';
                    if (!/^\d+$/.test(rawId)) {
                        return '';
                    }
                    const rowId = Number(rawId);
                    if (!Number.isSafeInteger(rowId) || rowId <= 0) {
                        return '';
                    }
                    const attachmentUrl = getAttachment(rowId);
                    if (typeof document !== 'undefined' && typeof document.createElement === 'function') {
                        const audio = document.createElement('audio');
                        audio.controls = true;
                        const source = document.createElement('source');
                        source.src = attachmentUrl;
                        source.type = mimeByExt[ext];
                        audio.appendChild(source);
                        return audio.outerHTML;
                    }
                    return '<audio controls><source src="' + escapeHtml(attachmentUrl) + '" type="' + mimeByExt[ext] + '"></audio>';
                }
            }
        ];

        function clonePlainObject(value) {
            if (!value || typeof value !== 'object') {
                return {};
            }
            if (typeof $ !== 'undefined' && typeof $.extend === 'function') {
                return $.extend(true, {}, value);
            }
            return JSON.parse(JSON.stringify(value));
        }

        function stripExportCell(value, config) {
            if (value === null || value === undefined) {
                return '';
            }
            if (typeof $ !== 'undefined' && $.fn && $.fn.dataTable && $.fn.dataTable.Buttons && typeof $.fn.dataTable.Buttons.stripData === 'function') {
                return $.fn.dataTable.Buttons.stripData(value, config);
            }
            if (typeof value !== 'string') {
                return value;
            }
            let str = value;
            str = str.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
            str = str.replace(/<!--[\s\S]*?-->/g, '');
            if (!config || config.stripHtml) {
                str = str.replace(/<[^>]*>/g, '');
            }
            if (!config || config.trim) {
                str = str.replace(/^\s+|\s+$/g, '');
            }
            if (!config || config.stripNewlines) {
                str = str.replace(/\r?\n/g, ' ');
            }
            if (!config || config.decodeEntities) {
                str = decodeHtmlEntities(str);
            }
            return str;
        }

        function getExportableColumnIndexes(mainDt) {
            const indexes = [];
            for (let i = 0; i < logActivityColumns.length; i++) {
                if (logActivityColumns[i].data === 'attachment') {
                    continue;
                }
                if (mainDt && typeof mainDt.column === 'function') {
                    const colApi = mainDt.column(i);
                    if (colApi && typeof colApi.visible === 'function' && colApi.visible() === false) {
                        continue;
                    }
                }
                indexes.push(i);
            }
            return indexes;
        }

        function buildExportRequestParams(mainDt) {
            const lastParams = (mainDt && mainDt.ajax && typeof mainDt.ajax.params === 'function')
                ? mainDt.ajax.params()
                : null;
            const params = (lastParams && typeof lastParams === 'object')
                ? clonePlainObject(lastParams)
                : {};

            if (!Array.isArray(params.columns) || params.columns.length === 0) {
                params.columns = logActivityColumns.map(function(col) {
                    return {
                        data: col.data,
                        name: col.name || '',
                        searchable: col.searchable !== false,
                        orderable: col.orderable !== false,
                        search: { value: '', regex: false }
                    };
                });
            }

            const $company = $('#Company');
            const companyVal = ($company && typeof $company.val === 'function') ? $company.val() : undefined;
            if (companyVal !== undefined) {
                params.company_id = companyVal;
            }

            const $startDate = $('#StartDate');
            const $endDate = $('#EndDate');
            params.start_date = ($startDate && typeof $startDate.val === 'function') ? ($startDate.val() || '') : '';
            params.end_date = ($endDate && typeof $endDate.val === 'function') ? ($endDate.val() || '') : '';

            const liveSearch = (mainDt && typeof mainDt.search === 'function')
                ? mainDt.search()
                : (params.search && typeof params.search.value === 'string' ? params.search.value : '');
            params.search = {
                value: liveSearch || '',
                regex: Boolean(params.search && (params.search.regex === true || params.search.regex === 'true'))
            };

            if (mainDt && typeof mainDt.order === 'function') {
                const currentOrder = mainDt.order();
                if (Array.isArray(currentOrder) && currentOrder.length > 0) {
                    params.order = currentOrder.map(function(item) {
                        return {
                            column: item[0],
                            dir: item[1]
                        };
                    });
                }
            }
            if (!Array.isArray(params.order) || params.order.length === 0) {
                params.order = [{ column: 0, dir: 'desc' }];
            }

            params.draw = (Number(params.draw) || 0) + 1;
            params.start = 0;
            params.length = -1;

            return params;
        }

        function setExportButtonsEnabled(mainDt, enabled) {
            if (mainDt && typeof mainDt.buttons === 'function') {
                const group = mainDt.buttons('.js-log-export-btn');
                if (group && typeof group.enable === 'function') {
                    group.enable(Boolean(enabled));
                }
            }
        }

        function notifyExportFeedback(level, message) {
            if (typeof toastr !== 'undefined' && toastr && typeof toastr[level] === 'function') {
                toastr[level](message);
                return;
            }
            if (level === 'error' && typeof alert === 'function') {
                alert(message);
            }
        }

        function buildFullExportAdapter(mainDt, rows, exportableIndexes) {
            const safeRows = Array.isArray(rows) ? rows : [];
            return {
                buttons: {
                    exportData: function(inOpts) {
                        const opts = (inOpts && typeof inOpts === 'object') ? inOpts : {};
                        const orthogonal = opts.orthogonal || 'export';
                        const stripCfg = {
                            stripHtml: opts.stripHtml !== false,
                            stripNewlines: opts.stripNewlines !== false,
                            decodeEntities: opts.decodeEntities !== false,
                            trim: opts.trim !== false
                        };

                        const indexes = Array.isArray(opts.columns)
                            ? opts.columns.filter(function(idx) { return exportableIndexes.indexOf(idx) !== -1; })
                            : exportableIndexes.slice();

                        const header = indexes.map(function(colIdx) {
                            let rawHeader = EXPORT_COLUMN_TITLES[colIdx] || '';
                            if (mainDt && typeof mainDt.column === 'function') {
                                const colApi = mainDt.column(colIdx);
                                const headerEl = colApi && typeof colApi.header === 'function' ? colApi.header() : null;
                                if (headerEl) {
                                    rawHeader = headerEl.innerHTML !== undefined
                                        ? headerEl.innerHTML
                                        : (headerEl.textContent || rawHeader);
                                }
                            }
                            const strippedHeader = stripExportCell(rawHeader, stripCfg);
                            return (opts.format && typeof opts.format.header === 'function')
                                ? opts.format.header(strippedHeader, colIdx)
                                : strippedHeader;
                        });

                        const body = safeRows.map(function(row, rowIdx) {
                            return indexes.map(function(colIdx) {
                                const colCfg = logActivityColumns[colIdx];
                                const field = colCfg ? colCfg.data : '';
                                const rawVal = row && field ? row[field] : '';
                                const rendered = (colCfg && typeof colCfg.render === 'function')
                                    ? colCfg.render(rawVal, orthogonal, row, { row: rowIdx, col: colIdx })
                                    : rawVal;
                                const strippedBody = stripExportCell(rendered, stripCfg);
                                return (opts.format && typeof opts.format.body === 'function')
                                    ? opts.format.body(strippedBody, rowIdx, colIdx)
                                    : strippedBody;
                            });
                        });

                        return {
                            header: header,
                            footer: null,
                            body: body
                        };
                    },
                    exportInfo: function(cfg) {
                        if (mainDt && mainDt.buttons && typeof mainDt.buttons.exportInfo === 'function') {
                            return mainDt.buttons.exportInfo(cfg);
                        }
                        return { title: 'Log Activities', filename: 'Log Activities', messageTop: null, messageBottom: null };
                    },
                    info: function(title, message, time) {
                        if (mainDt && mainDt.buttons && typeof mainDt.buttons.info === 'function') {
                            return mainDt.buttons.info(title, message, time);
                        }
                    }
                },
                table: function() {
                    if (mainDt && typeof mainDt.table === 'function') {
                        return mainDt.table();
                    }
                    return { node: function() { return { className: '' }; }, container: function() { return null; } };
                },
                i18n: function(token, def, plural) {
                    if (mainDt && typeof mainDt.i18n === 'function') {
                        return mainDt.i18n(token, def, plural);
                    }
                    return def;
                },
                settings: function() {
                    if (mainDt && typeof mainDt.settings === 'function') {
                        return mainDt.settings();
                    }
                    return [{ aoColumns: [] }];
                },
                columns: function(selector) {
                    if (mainDt && typeof mainDt.columns === 'function') {
                        return mainDt.columns(selector);
                    }
                    return { flatten: function() { return { map: function() { return { toArray: function() { return []; } }; } }; } };
                },
                column: function(idx) {
                    if (mainDt && typeof mainDt.column === 'function') {
                        return mainDt.column(idx);
                    }
                    return { index: function() { return idx; } };
                }
            };
        }

        function runFullLogActivityExport(nativeButtonName, btnApi, e, mainDt, buttonNode, config) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }
            if (exportInFlight) {
                return;
            }

            const extButtons = ($.fn && $.fn.dataTable && $.fn.dataTable.ext && $.fn.dataTable.ext.buttons)
                ? $.fn.dataTable.ext.buttons
                : null;
            const nativeButton = extButtons && extButtons[nativeButtonName];
            if (!nativeButton || typeof nativeButton.action !== 'function') {
                notifyExportFeedback('error', 'Export feature is currently unavailable.');
                return;
            }

            const exportableIndexes = getExportableColumnIndexes(mainDt);
            if (!exportableIndexes.length) {
                notifyExportFeedback('info', 'No visible columns are available to export.');
                return;
            }

            exportInFlight = true;
            if (btnApi && typeof btnApi.processing === 'function') {
                btnApi.processing(true);
            }
            setExportButtonsEnabled(mainDt, false);

            const requestParams = buildExportRequestParams(mainDt);
            const releaseUiLock = function(stopProcessing) {
                exportInFlight = false;
                setExportButtonsEnabled(mainDt, true);
                if (stopProcessing && btnApi && typeof btnApi.processing === 'function') {
                    btnApi.processing(false);
                }
            };

            $.ajax({
                url: logActivityAjaxUrl,
                type: 'GET',
                data: requestParams,
                dataType: 'json',
                headers: {
                    Accept: 'application/json'
                },
                success: function(response) {
                    const rows = response && Array.isArray(response.data) ? response.data : null;
                    if (!rows) {
                        releaseUiLock(true);
                        notifyExportFeedback('error', 'Failed to export log activities: invalid server response.');
                        return;
                    }
                    if (rows.length === 0) {
                        releaseUiLock(true);
                        notifyExportFeedback('info', 'No matching log activities found to export.');
                        return;
                    }

                    const exportDt = buildFullExportAdapter(mainDt, rows, exportableIndexes);
                    const exportConfig = $.extend(true, {}, nativeButton, config, {
                        exportOptions: {
                            columns: exportableIndexes,
                            orthogonal: 'export'
                        }
                    });

                    try {
                        nativeButton.action.call(btnApi, e, exportDt, buttonNode, exportConfig);
                        releaseUiLock(nativeButtonName === 'print');
                    } catch (err) {
                        releaseUiLock(true);
                        notifyExportFeedback('error', 'Failed to generate export file.');
                    }
                },
                error: function(xhr) {
                    releaseUiLock(true);
                    const serverMsg = xhr && xhr.responseJSON && typeof xhr.responseJSON.message === 'string' && xhr.responseJSON.message.trim() !== ''
                        ? xhr.responseJSON.message.trim()
                        : 'Failed to export log activities. Please try again.';
                    notifyExportFeedback('error', serverMsg);
                }
            });
        }

        function notifyInvalidDateRange(startDate, endDate) {
            if (startDate && endDate && startDate > endDate && typeof toastr !== 'undefined' && toastr && typeof toastr.warning === 'function') {
                toastr.warning('Start date is after end date. Showing empty results until adjusted.');
            }
        }

        function triggerFilterReload(resetPaging) {
            const startDate = $('#StartDate').val();
            const endDate = $('#EndDate').val();
            notifyInvalidDateRange(startDate, endDate);
            if (Table && Table.ajax && typeof Table.ajax.reload === 'function') {
                Table.ajax.reload(null, resetPaging === undefined ? true : Boolean(resetPaging));
            }
        }

        var Table = $('#daTable').DataTable({
            ajax: {
                url: "{{ route('admin.log-activity.index') }}",
                data: function (d) {
                    d.company_id = $('#Company').val();
                    d.start_date = $('#StartDate').val();
                    d.end_date = $('#EndDate').val();
                }
            },
            processing: true,
            serverSide: true,
            responsive: true,
            order: [[0, 'desc']],
            columns: logActivityColumns,
            dom: '<"row"<"col-sm-3"l><"col-sm-6"B><"col-sm-3"fr>>t<"row"<"col-sm-5"i><"col-sm-7"p>>',
            buttons: [{
                    extend: 'copy',
                    className: 'btn-sm btn-info js-log-export-btn',
                    text: '<i class="fas fa-copy"></i> Copy',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)',
                        orthogonal: 'export'
                    },
                    action: function(e, dt, button, config) {
                        runFullLogActivityExport('copyHtml5', this, e, dt, button, config);
                    }
                },
                {
                    extend: 'excel',
                    className: 'btn-sm btn-info js-log-export-btn',
                    text: '<i class="fa fa-file-excel"></i> Excel',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)',
                        orthogonal: 'export'
                    },
                    action: function(e, dt, button, config) {
                        runFullLogActivityExport('excelHtml5', this, e, dt, button, config);
                    }
                },
                {
                    extend: 'pdf',
                    className: 'btn-sm btn-info js-log-export-btn',
                    text: '<i class="fas fa-file-pdf"></i> Pdf',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)',
                        orthogonal: 'export'
                    },
                    action: function(e, dt, button, config) {
                        runFullLogActivityExport('pdfHtml5', this, e, dt, button, config);
                    }
                },
                {
                    extend: 'print',
                    className: 'btn-sm btn-info js-log-export-btn',
                    text: '<i class="fas fa-print"></i> Print',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)',
                        orthogonal: 'export'
                    },
                    action: function(e, dt, button, config) {
                        runFullLogActivityExport('print', this, e, dt, button, config);
                    }
                },
                {
                    extend: 'print',
                    className: 'btn-sm btn-info',
                    text: '<i class="fas fa-sync"></i> Refresh',
                    action: function() {
                        reload();
                    }
                }
            ]
        });

        function reload() {
            Table.ajax.reload(null, false);
        }

        function escapeHtml(value) {
            if (value === null || value === undefined) {
                return '';
            }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function decodeHtmlEntities(value) {
            if (value === null || value === undefined) {
                return '';
            }
            return String(value).replace(/&(amp|lt|gt|quot|#0?39);/g, function(entity, code) {
                switch (code) {
                    case 'amp':
                        return '&';
                    case 'lt':
                        return '<';
                    case 'gt':
                        return '>';
                    case 'quot':
                        return '"';
                    case '#039':
                    case '#39':
                        return "'";
                    default:
                        return entity;
                }
            });
        }

        function getAttachment(id) {
            let attachment_url = "{{ route('admin.log-activity.attachment', ':id') }}";
            return attachment_url.replace(':id', encodeURIComponent(String(id)));
        }

        $(document).ready(function() {
            const handleDateKeydown = function(e) {
                if (e && (e.key === 'Enter' || e.keyCode === 13 || e.which === 13)) {
                    if (typeof e.preventDefault === 'function') {
                        e.preventDefault();
                    }
                    triggerFilterReload(true);
                }
            };

            $('#Company').on('change', function() {
                triggerFilterReload(true);
            });

            $('#StartDate').on('keydown', handleDateKeydown);
            $('#EndDate').on('keydown', handleDateKeydown);

            $('#btnFilter').on('click', function() {
                triggerFilterReload(true);
            });

            $('#btnClear').on('click', function() {
                $('#Company').val('');
                $('#StartDate').val('');
                $('#EndDate').val('');
                $('input[type="date"]').val('');
                if (Table && typeof Table.search === 'function' && Table.search() !== '') {
                    Table.search('');
                }
                if (Table && Table.ajax && typeof Table.ajax.reload === 'function') {
                    Table.ajax.reload(null, true);
                }
            });

            const $table = $('#daTable');
            if ($table && typeof $table.on === 'function') {
                $table.on('click', '.js-view-location', function(e) {
                    if (e && typeof e.preventDefault === 'function') {
                        e.preventDefault();
                    }
                    const el = this;
                    const readAttr = function(attrName) {
                        if (el && typeof el.getAttribute === 'function') {
                            return el.getAttribute(attrName);
                        }
                        if (el && el.attributes && Object.prototype.hasOwnProperty.call(el.attributes, attrName)) {
                            return el.attributes[attrName];
                        }
                        return $(el).attr(attrName);
                    };

                    openLocationMapModal({
                        latitude: readAttr('data-lat'),
                        longitude: readAttr('data-lng'),
                        name: readAttr('data-user'),
                        created_at: readAttr('data-timestamp'),
                        label: readAttr('data-label') || 'Updating Location'
                    });
                });
            }

            const $locationModal = $('#locationMapModal');
            if ($locationModal && typeof $locationModal.on === 'function') {
                $locationModal.on('shown.bs.modal', function() {
                    isLocationModalShown = true;
                    isLocationModalClosing = false;
                    const initialCoords = pendingLocation
                        ? parseValidCoordinates(pendingLocation.latitude, pendingLocation.longitude)
                        : null;
                    const map = ensureActivityMap(initialCoords);
                    if (map && typeof map.invalidateSize === 'function') {
                        map.invalidateSize();
                    }
                    if (pendingLocation) {
                        renderTemporaryPin(pendingLocation);
                    }
                });

                $locationModal.on('hide.bs.modal', function() {
                    isLocationModalShown = false;
                    isLocationModalClosing = true;
                    pendingReopenLocation = null;
                    clearTemporaryPin();
                });

                $locationModal.on('hidden.bs.modal', function() {
                    isLocationModalShown = false;
                    isLocationModalClosing = false;
                    clearTemporaryPin();
                    if (!pendingReopenLocation) {
                        pendingLocation = null;
                        const $meta = $('#locationMapMeta');
                        if ($meta && typeof $meta.text === 'function') {
                            $meta.text('');
                        }
                    }
                });
            }
        });
    </script>
@endpush
@section('content')
    <!--**********************************
                                            Content body start
                                        ***********************************-->
    <div class="content-body">

        <div class="container-fluid">
            <div class="row">
                <div class="col-12 m-b-30">
                    <div class="row">
                        <div class="col">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">{{ $title }} Table</h5>
                                    <div class="row justify-content-end mb-3">
                                        <div class="col-md-10 ml-auto">
                                            <div class="row">
                                                @if (Auth::user()->isSuperAdmin())
                                                    <div class="col-md-3">
                                                        <select name="company_id" id="Company" class="form-control mr-2">
                                                            <option value="">All Companies</option>
                                                            @foreach ($companies as $company)
                                                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endif
                                                <div class="col">
                                                    <div class="input-group mb-3">
                                                        <input type="date" id="StartDate" name="start_date" class="form-control" aria-label="Start date">
                                                        <div class="input-group-append">
                                                            <span class="input-group-text">To</span>
                                                        </div>
                                                        <input type="date" id="EndDate" name="end_date" class="form-control" aria-label="End date">
                                                    </div>
                                                </div>
                                                <div class="col">
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-primary" id="btnFilter">Filter</button>
                                                        <button type="button" class="btn btn-danger" id="btnClear">Clear</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-bordered" id="daTable">
                                            <thead>
                                                <tr>
                                                    <th>CREATED AT</th>
                                                    <th>COMPANY</th>
                                                    <th>NAME</th>
                                                    <th>ACTIVITY</th>
                                                    <th>ATTACHMENT</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- End Col -->
                    </div>
                </div>
            </div>
        </div>
        <!-- #/ container -->
    </div>
    <!-- Location Map Modal -->
    <div class="modal fade" id="locationMapModal" tabindex="-1" role="dialog" aria-labelledby="locationMapModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="locationMapModalTitle">Location Details</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="mb-2 text-muted small" id="locationMapMeta"></div>
                    <div id="activityLocationMap" style="height: 420px; width: 100%;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary text-white" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <!--**********************************
                    Content body end
                ***********************************-->
@endsection
