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
    <script src="{{ asset('assets/icons/font-awesome/js/all.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/toastr/js/toastr.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/bootstrap-select/dist/js/bootstrap-select.min.js') }}"></script>
    <script>
        let url = '';
        let method = '';
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        var Table = $('#daTable').DataTable({
            ajax: "{{ route('admin.log-activity.index') }}",
            processing: true,
            serverSide: true,
            responsive: true,
            columns: [{
                    data: 'created_at'
                },
                {
                    data: 'company'
                },
                {
                    data: 'name'
                },
                {
                    data: 'activity'
                },
                {
                    data: 'attachment',
                    name: 'attachment',
                    render: function(data, type, row, meta) {
                        if (data !== null) {
                            let fileType = data.split(".");
                            if (fileType.at(-1) == 'mp3' || fileType.at(-1) == 'wav') {
                                return '<audio controls>' +
                                    '<source src="' + getAttachment(data) + '" type="audio/ogg">' +
                                    '</audio>';
                            }
                        } else {
                            return '';
                        }
                    }
                }
            ],
            dom: '<"row"<"col-sm-3"l><"col-sm-6"B><"col-sm-3"fr>>t<"row"<"col-sm-5"i><"col-sm-7"p>>',
            buttons: [{
                    extend: 'copy',
                    className: 'btn-sm btn-info',
                    text: '<i class="fas fa-copy"></i> Copy',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)'
                    }
                },
                {
                    extend: 'excel',
                    className: 'btn-sm btn-info',
                    text: '<i class="fa fa-file-excel"></i> Excel',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)'
                    },
                },
                {
                    extend: 'pdf',
                    className: 'btn-sm btn-info',
                    text: '<i class="fas fa-file-pdf"></i> Pdf',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)'
                    }
                },
                {
                    extend: 'print',
                    className: 'btn-sm btn-info',
                    text: '<i class="fas fa-print"></i> Print',
                    exportOptions: {
                        columns: ':visible th:not(:last-child)'
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

        function getAttachment(title) {
            let asset_url = "{{ asset(':attachment') }}";
            return asset_url.replace(':attachment', title);
        }
        $(document).ready(function() {})
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
                                                <div class="col">
                                                    <select name="company_id" id="Company" class="form-control mr-2">
                                                        <option value="HMV">HMV</option>
                                                    </select>
                                                </div>
                                                <div class="col">
                                                    <div class="input-group mb-3">
                                                        <input type="date" class="form-control">
                                                        <div class="input-group-append">
                                                            <span class="input-group-text">To</span>
                                                        </div>
                                                        <input type="date" class="form-control">
                                                    </div>
                                                </div>
                                                <div class="col">
                                                    <div class="btn-group">
                                                        <button class="btn btn-primary">Filter</button>
                                                        <button class="btn btn-danger">Clear</button>
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
    <!--**********************************
                    Content body end
                ***********************************-->
@endsection
