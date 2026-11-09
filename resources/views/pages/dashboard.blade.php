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
        let googleTile = L.tileLayer('http://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            attribution: ''
        });
        let osmTile = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        });
        let googleTerrain = L.tileLayer('http://{s}.google.com/vt?lyrs=p&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
        });
        let googleSat = L.tileLayer('http://{s}.google.com/vt?lyrs=s&x={x}&y={y}&z={z}', {
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
            layers: [osmTile, googleTile]
        });
        var layerControl = L.control.layers(baseMaps).addTo(map);
        var markersLayer = new L.LayerGroup().addTo(map);
        var markersById = {};
        var isInitialLoad = true;
        var markersTimer = null;
        var pollIntervalMs = 10000;
        var isFetchingMarkers = false;
        var activeMarkersXhr = null;

        function createPopupContent(name, formattedTime) {
            var popupContent = document.createElement('div');
            popupContent.appendChild(document.createTextNode(name || ''));
            popupContent.appendChild(document.createElement('br'));
            popupContent.appendChild(document.createTextNode(formattedTime || ''));
            return popupContent;
        }

        function onClick(e) {
            var popup = e.target.getPopup();
            var content = popup ? popup.getContent() : null;
        }

        function markersUser() {
            if (isFetchingMarkers) {
                return;
            }
            isFetchingMarkers = true;

            activeMarkersXhr = $.ajax({
                url: "{{ route('auth.dashboard.markers') }}",
                method: "GET",
                dataType: "json",
                success: function(res) {
                    var currentIds = {};
                    var latLngs = [];

                    if (res && res.data && Array.isArray(res.data)) {
                        $.each(res.data, function(idx, val) {
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
                                    var deviceId = val.id != null ? val.id : (val.position && val.position.id ? val.position.id : idx);
                                    currentIds[deviceId] = true;

                                    var formattedTime = '';
                                    if (val.position.created_at) {
                                        try {
                                            formattedTime = val.code
                                                ? moment(val.position.created_at).tz(val.code).format('DD MMM YYYY HH:mm z')
                                                : moment(val.position.created_at).format('DD MMM YYYY HH:mm');
                                        } catch (e) {
                                            formattedTime = moment(val.position.created_at).format('DD MMM YYYY HH:mm');
                                        }
                                    }

                                    var popupContent = createPopupContent(val.name, formattedTime);

                                    if (markersById[deviceId]) {
                                        var existingMarker = markersById[deviceId];
                                        var currentLatLng = existingMarker.getLatLng();
                                        if (currentLatLng.lat !== lat || currentLatLng.lng !== lng) {
                                            existingMarker.setLatLng([lat, lng]);
                                        }
                                        existingMarker.setPopupContent(popupContent);
                                    } else {
                                        var marker = L.marker([lat, lng])
                                            .bindPopup(popupContent)
                                            .on('click', onClick);

                                        markersLayer.addLayer(marker);
                                        markersById[deviceId] = marker;
                                    }

                                    latLngs.push([lat, lng]);
                                }
                            }
                        });
                    }

                    // Remove markers for devices that are no longer returned
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
                },
                complete: function() {
                    isFetchingMarkers = false;
                    activeMarkersXhr = null;
                }
            });
        }

        function startMarkersPolling() {
            stopMarkersPolling();
            markersTimer = setInterval(function() {
                markersUser();
            }, pollIntervalMs);
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

        function refreshMarkers() {
            markersUser();
            startMarkersPolling();
        }

        // Cleanup timer and any in-flight request when navigating away
        window.addEventListener('beforeunload', stopMarkersPolling);
        window.addEventListener('pagehide', stopMarkersPolling);

        $(document).ready(function() {
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
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <h5 class="card-title">User Location</h5>
                                        </div>
                                        <div class="col-md-6">
                                            <button class="btn btn-sm btn-primary float-right" onclick="refreshMarkers()"><i
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
