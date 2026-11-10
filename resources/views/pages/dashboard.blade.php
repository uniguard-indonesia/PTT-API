@extends('layouts.default')
@section('title')
    {{ $title }}
@endsection
@push('css')
    <link rel="stylesheet" href="{{ asset('assets/plugins/toastr/css/toastr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/leaflet/leaflet.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('assets/plugins/chart.js/Chart.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/toastr/js/toastr.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('assets/plugins/moment/moment.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/moment/moment-timezone.min.js') }}"></script>
    <script>
        function notification(status, message) {
            if (status == 'error') {
                toastr.error(message, status.toUpperCase(), {
                    closeButton: 1,
                    showDuration: "300",
                    hideDuration: "1000",
                    showMethod: "fadeIn",
                    hideMethod: "fadeOut",
                    timeOut: 5e3,
                    showEasing: "swing",
                    hideEasing: "linear"
                });
            } else {
                toastr.success(message, status.toUpperCase(), {
                    closeButton: 1,
                    showDuration: "300",
                    hideDuration: "1000",
                    showMethod: "fadeIn",
                    hideMethod: "fadeOut",
                    timeOut: 5e3,
                    showEasing: "swing",
                    hideEasing: "linear"
                });
            }
        }
        let googleTile = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}&hl=en', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            attribution: ''
        });
        let osmTile = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        });
        let googleTerrain = L.tileLayer('https://{s}.google.com/vt?lyrs=p&x={x}&y={y}&z={z}&hl=en', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
        });
        let googleSat = L.tileLayer('https://{s}.google.com/vt?lyrs=s&x={x}&y={y}&z={z}&hl=en', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
        });
        var baseMaps = {
            "OpenStreetMap": osmTile,
            "Google Satellites": googleTile,
            "Google Terrain": googleTerrain
        };
        var defaultCenter = [-25.2744, 133.7751];
        var defaultZoom = 4;
        var map = L.map('map', {
            center: defaultCenter,
            zoom: defaultZoom,
            layers: [googleTile]
        });
        var layerControl = L.control.layers(baseMaps).addTo(map);
        var markersLayer = new L.LayerGroup().addTo(map);
        var markersById = {};
        var isInitialLoad = true;
        var lastMarkersData = [];
        var hasLoadedMarkersData = false;
        var markersTimer = null;
        var pollIntervalMs = 10000;
        var isFetchingMarkers = false;
        var activeMarkersXhr = null;
        var isMarkersPollingDisabled = false;

        function createPopupContent(name, formattedTime) {
            var popupContent = document.createElement('div');
            popupContent.appendChild(document.createTextNode(name || ''));
            popupContent.appendChild(document.createElement('br'));
            popupContent.appendChild(document.createTextNode(formattedTime || ''));
            return popupContent;
        }

        function parseMarkerTimestamp(value) {
            if (typeof value !== 'string') {
                return null;
            }
            var trimmed = value.trim();
            if (trimmed === '') {
                return null;
            }

            // Supports Laravel ISO-8601 timestamps (with Z or +/-HH[:MM]) and YYYY-MM-DD[T| ]HH:mm(:ss)
            var match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?(Z|[+-]\d{2}(?::?\d{2})?)?$/i.exec(trimmed);
            if (!match) {
                return null;
            }

            var year = Number(match[1]);
            var month = Number(match[2]);
            var day = Number(match[3]);
            var hasTime = match[4] !== undefined;
            var hour = hasTime ? Number(match[4]) : 0;
            var minute = hasTime ? Number(match[5]) : 0;
            var second = match[6] !== undefined ? Number(match[6]) : 0;
            var ms = match[7] !== undefined ? Number(match[7].slice(0, 3).padEnd(3, '0')) : 0;
            var tz = match[8];

            if ((!hasTime && tz) || month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || second > 59) {
                return null;
            }

            if (tz) {
                var utcCheck = new Date(Date.UTC(year, month - 1, day, hour, minute, second, ms));
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

                var offsetMinutes = 0;
                if (tz.toUpperCase() !== 'Z') {
                    var sign = tz.charAt(0) === '-' ? -1 : 1;
                    var tzDigits = tz.slice(1).replace(':', '');
                    var tzHours = Number(tzDigits.slice(0, 2));
                    var tzMins = tzDigits.length === 4 ? Number(tzDigits.slice(2, 4)) : 0;
                    if (tzHours > 23 || tzMins > 59) {
                        return null;
                    }
                    offsetMinutes = sign * (tzHours * 60 + tzMins);
                }

                return new Date(utcCheck.getTime() - offsetMinutes * 60000);
            }

            var localDate = new Date(year, month - 1, day, hour, minute, second, ms);
            if (
                Number.isNaN(localDate.getTime()) ||
                localDate.getFullYear() !== year ||
                localDate.getMonth() !== month - 1 ||
                localDate.getDate() !== day
            ) {
                return null;
            }

            return localDate;
        }

        function getOneMonthCutoffDate(referenceDate) {
            var now = referenceDate instanceof Date ? referenceDate : new Date();
            var year = now.getFullYear();
            var month = now.getMonth();
            var day = now.getDate();
            var targetYear = month === 0 ? year - 1 : year;
            var targetMonth = month === 0 ? 11 : month - 1;
            var daysInTargetMonth = new Date(targetYear, targetMonth + 1, 0).getDate();
            var clampedDay = Math.min(day, daysInTargetMonth);

            return new Date(
                targetYear,
                targetMonth,
                clampedDay,
                now.getHours(),
                now.getMinutes(),
                now.getSeconds(),
                now.getMilliseconds()
            );
        }

        function isPositionOlderThanOneMonth(createdAt, referenceDate) {
            var parsedDate = parseMarkerTimestamp(createdAt);
            // Safe fallback: pins with missing/unparseable timestamps remain visible rather than silently hidden
            if (!parsedDate) {
                return false;
            }
            var cutoffDate = getOneMonthCutoffDate(referenceDate);
            return parsedDate.getTime() < cutoffDate.getTime();
        }

        function shouldHideStalePins() {
            var checkbox = document.getElementById('hideStalePinsCheckbox');
            if (checkbox) {
                return Boolean(checkbox.checked);
            }
            var $checkbox = $('#hideStalePinsCheckbox');
            if ($checkbox && typeof $checkbox.is === 'function') {
                return Boolean($checkbox.is(':checked'));
            }
            return true;
        }

        function renderMarkers(data) {
            var currentIds = Object.create(null);
            var latLngs = [];
            var hideStalePins = shouldHideStalePins();
            var now = new Date();

            if (Array.isArray(data)) {
                $.each(data, function(idx, val) {
                    if (
                        val &&
                        val.position &&
                        (typeof val.position.latitude === 'number' ||
                            (typeof val.position.latitude === 'string' && val.position.latitude.trim() !== '')) &&
                        (typeof val.position.longitude === 'number' ||
                            (typeof val.position.longitude === 'string' && val.position.longitude.trim() !== ''))
                    ) {
                        var lat = Number(val.position.latitude);
                        var lng = Number(val.position.longitude);

                        if (
                            Number.isFinite(lat) &&
                            Number.isFinite(lng) &&
                            lat >= -90 &&
                            lat <= 90 &&
                            lng >= -180 &&
                            lng <= 180
                        ) {
                            if (hideStalePins && isPositionOlderThanOneMonth(val.position.created_at, now)) {
                                return;
                            }

                            var deviceId = val.id != null ? val.id : (val.position && val.position.id != null ? val.position.id : idx);
                            currentIds[deviceId] = true;

                            var formattedTime = '';
                            if (val.position.created_at && parseMarkerTimestamp(val.position.created_at)) {
                                try {
                                    formattedTime = val.code
                                        ? moment(val.position.created_at).tz(val.code).format('DD MMM YYYY HH:mm z')
                                        : moment(val.position.created_at).format('DD MMM YYYY HH:mm');
                                } catch (e) {
                                    try {
                                        formattedTime = moment(val.position.created_at).format('DD MMM YYYY HH:mm');
                                    } catch (e2) {
                                        formattedTime = '';
                                    }
                                }
                            }

                            var popupContent = createPopupContent(val.name, formattedTime);

                            if (Object.prototype.hasOwnProperty.call(markersById, deviceId)) {
                                var existingMarker = markersById[deviceId];
                                var currentLatLng = existingMarker.getLatLng();
                                if (currentLatLng.lat !== lat || currentLatLng.lng !== lng) {
                                    existingMarker.setLatLng([lat, lng]);
                                }
                                existingMarker.setPopupContent(popupContent);
                            } else {
                                var marker = L.marker([lat, lng])
                                    .bindPopup(popupContent);

                                markersLayer.addLayer(marker);
                                markersById[deviceId] = marker;
                            }

                            latLngs.push([lat, lng]);
                        }
                    }
                });
            }

            // Remove markers for devices that are no longer returned or are currently filtered out
            Object.keys(markersById).forEach(function(id) {
                if (!currentIds[id]) {
                    markersLayer.removeLayer(markersById[id]);
                    delete markersById[id];
                }
            });

            if (!map.hasLayer(markersLayer)) {
                markersLayer.addTo(map);
            }

            // Only auto-center / fitBounds on the initial page load
            if (isInitialLoad) {
                if (latLngs.length === 0) {
                    map.setView(defaultCenter, defaultZoom);
                } else if (latLngs.length === 1) {
                    map.flyTo(latLngs[0], 14);
                } else {
                    var bounds = L.latLngBounds(latLngs);
                    map.fitBounds(bounds, {
                        padding: [50, 50],
                        maxZoom: 16
                    });
                }
                isInitialLoad = false;
            }
        }

        function markersUser() {
            if (isFetchingMarkers || isMarkersPollingDisabled) {
                return;
            }
            isFetchingMarkers = true;

            activeMarkersXhr = $.ajax({
                url: "{{ route('auth.dashboard.markers') }}",
                method: "GET",
                dataType: "json",
                success: function(res) {
                    lastMarkersData = (res && res.data && Array.isArray(res.data)) ? res.data : [];
                    hasLoadedMarkersData = true;
                    renderMarkers(lastMarkersData);
                },
                error: function(xhr, textStatus) {
                    if (textStatus === 'abort') {
                        return;
                    }

                    var status = xhr ? xhr.status : 0;
                    if (status === 401 || status === 419 || textStatus === 'parsererror') {
                        isMarkersPollingDisabled = true;
                        clearMarkersPollingTimer();
                        notification('error', 'Your session has expired. Please reload the dashboard.');
                    }
                },
                complete: function() {
                    isFetchingMarkers = false;
                    activeMarkersXhr = null;
                }
            });
        }

        function clearMarkersPollingTimer() {
            if (markersTimer) {
                clearInterval(markersTimer);
                markersTimer = null;
            }
        }

        function startMarkersPolling() {
            clearMarkersPollingTimer();
            if (isMarkersPollingDisabled) {
                return;
            }
            markersTimer = setInterval(function() {
                markersUser();
            }, pollIntervalMs);
        }

        function stopMarkersPolling() {
            clearMarkersPollingTimer();
            if (activeMarkersXhr && typeof activeMarkersXhr.abort === 'function') {
                activeMarkersXhr.abort();
                activeMarkersXhr = null;
            }
            isFetchingMarkers = false;
        }

        function refreshMarkers() {
            markersUser();
            startMarkersPolling();
        }

        // Cleanup timer and any in-flight request when navigating away
        window.addEventListener('beforeunload', stopMarkersPolling);
        window.addEventListener('pagehide', stopMarkersPolling);

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearMarkersPollingTimer();
            } else if (!isMarkersPollingDisabled) {
                markersUser();
                startMarkersPolling();
            }
        });

        $(document).ready(function() {
            $('#hideStalePinsCheckbox').on('change', function() {
                if (hasLoadedMarkersData) {
                    renderMarkers(lastMarkersData);
                }
            });
            markersUser();
            startMarkersPolling();
        });
    </script>
@endpush
@section('content')
    <!--**********************************
                                    Content body start
                                ***********************************-->
    <div class="content-body">

        <div class="row page-titles mx-0">
            <div class="col p-md-0">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="javascript:void(0)">Dashboard</a></li>
                </ol>
            </div>
        </div>
        <!-- row -->

        <div class="container-fluid">
            <div class="row">
                <!-- Material statustic card start -->
                <div class="col-xl-12 col-md-12">
                    <div class="card mat-stat-card">
                        <div class="card-block">
                            <div class="row align-items-center b-b-default">
                                <div class="col-sm-4 b-r-default p-b-20 p-t-20">
                                    <div class="row align-items-center text-center">
                                        <div class="col-4 p-r-0">
                                            <i class="fas fa-user text-c-purple f-24"></i>
                                        </div>
                                        <div class="col-8 p-l-0">
                                            <h5>{{ $countUser }}</h5>
                                            <p class="text-muted m-b-0">User</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-4 b-r-default p-b-20 p-t-20">
                                    <div class="row align-items-center text-center">
                                        <div class="col-4 p-r-0">
                                            <i class="fas fa-suitcase text-c-blue f-24"></i>
                                        </div>
                                        <div class="col-8 p-l-0">
                                            <h5>{{ $countCompanies }}</h5>
                                            <p class="text-muted m-b-0">Company</p>
                                        </div>
                                    </div>
                                </div>
                                @if (isset($countServer) && $countServer !== null)
                                <div class="col-sm-4 b-r-default p-b-20 p-t-20">
                                    <div class="row align-items-center text-center">
                                        <div class="col-4 p-r-0">
                                            <i class="fas fa-server text-c-green f-24"></i>
                                        </div>
                                        <div class="col-8 p-l-0">
                                            <h5>{{ $countServer }}</h5>
                                            <p class="text-muted m-b-0">{{ Auth::user()->level_id == 0 ? 'Server' : 'Assigned Server' }}</p>
                                        </div>
                                    </div>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Start Row -->
            <div class="row">
                <div class="col-12 m-b-30">
                    {{-- <h4 class="d-inline">User Location</h4> --}}
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="row mb-3 align-items-center">
                                        <div class="col-md-5 col-12 mb-2 mb-md-0">
                                            <h5 class="card-title mb-0">User Location</h5>
                                        </div>
                                        <div class="col-md-7 col-12 d-flex flex-wrap align-items-center justify-content-between justify-content-md-end">
                                            <div class="form-check form-check-inline my-1 mr-md-3 mr-2">
                                                <input class="form-check-input" type="checkbox" id="hideStalePinsCheckbox" checked autocomplete="off" style="cursor: pointer;">
                                                <label class="form-check-label mb-0" for="hideStalePinsCheckbox" style="cursor: pointer;">Hide pins older than 1 month</label>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-primary my-1" onclick="refreshMarkers()"><i
                                                    class="fas fa-sync"></i> Refresh</button>
                                        </div>
                                    </div>
                                    <div id="map" style="height: 500px">
                                        <div class="btn-group float-right" style="z-index: 1001">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Row -->
        </div>
        <!-- #/ container -->
    </div>
    <!--**********************************
                Content body end
            ***********************************-->
@endsection
