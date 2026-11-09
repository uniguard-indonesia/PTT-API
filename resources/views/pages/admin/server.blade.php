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

        function getUrl() {
            return url;
        }

        function getMethod() {
            return method;
        }
        var Table = $('#daTable').DataTable({
            ajax: "{{ route('admin.server.index') }}",
            processing: true,
            serverSide: true,
            responsive: true,
            columns: [{
                    data: 'DT_RowIndex'
                },
                {
                    data: 'name'
                },
                {
                    data: 'host'
                },
                {
                    data: 'port'
                },
                {
                    data: 'username'
                },
                {
                    data: 'action'
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
                    action: function(){
                        reload();
                    }
                }
            ]
        });

        function reload()
        {
            Table.ajax.reload(null, false);
        }

        function create() {
            url = "{{ route('admin.server.store') }}";
            method = 'POST';
            $('#basicModal .modal-header h5').html("Create Server");
            resetPasswordVisibility('#basicModal');
            @if (Auth::user()->level_id != 0)
                $('#Companies').selectpicker('val', ['{{ Auth::user()->company_id }}']);
            @endif
            $('#basicModal').modal('show');
        }

        function edit(id) {
            let editUrl = "{{ route('admin.server.edit', ':id') }}";
            editUrl = editUrl.replace(':id', id);
            url = "{{ route('admin.server.update', ':id') }}";
            url = url.replace(':id', id);
            method = "POST";
            $('.modal-body form').append('<input type="hidden" name="_method" value="PUT" />');
            $.get(editUrl, function(res) {
                $('#basicModal .modal-header h5').html("Edit Server");
                let companies = [];
                $.each(res.data.companies, function(idx, item) {
                    companies.push(item.id);
                })
                @if (Auth::user()->level_id != 0)
                    if (companies.length === 0) {
                        companies = ['{{ Auth::user()->company_id }}'];
                    }
                @endif
                $('#Companies').selectpicker('val', companies);
                $('input[name="name"]').val(res.data.name);
                $('input[name="username"]').val(res.data.username);
                $('input[name="host"]').val(res.data.host);
                $('input[name="port"]').val(res.data.port);
                $('input[name="password"]').val(res.data.password);
                resetPasswordVisibility('#basicModal');
                $('#basicModal').modal('show');
            })
        }

        function destroy(id) {
            let deleteUrl = "{{ route('admin.server.destroy', ':id') }}";
            deleteUrl = deleteUrl.replace(':id', id);
            swal({
                title: 'Delete this data?',
                text: "Data will removed permanently!",
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#F44336',
                reverseButtons: true
            }).then((isConfirm) => {
                if (isConfirm.value) {
                    $.ajax({
                        url: deleteUrl,
                        type: "DELETE",
                        dataType: "JSON",
                        success: function(response) {
                            notification(response.status, response.message);
                            Table.ajax.reload(null, false);
                        },
                        error: function(res) {
                            notification(res.responseJSON.status, res.responseJSON.message);
                        }
                    })
                }
            })
        }

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

        function showPassword(that) {
            let $btn = $(that).closest('button').length ? $(that).closest('button') : $(that);
            let $input = $btn.closest('.input-group').find('input');
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $btn.html('<i class="fa fa-eye-slash" aria-hidden="true"></i>');
                $btn.attr('aria-label', 'Hide password');
            } else {
                $input.attr('type', 'password');
                $btn.html('<i class="fa fa-eye" aria-hidden="true"></i>');
                $btn.attr('aria-label', 'Show password');
            }
        }

        function resetPasswordVisibility(modalSelector) {
            let $modal = $(modalSelector);
            $modal.find('.password input').attr('type', 'password');
            $modal.find('.password button').each(function() {
                $(this).html('<i class="fa fa-eye" aria-hidden="true"></i>');
                $(this).attr('aria-label', 'Show password');
            });
        }

        $(document).ready(function() {
            $('#basicModal').on('hide.bs.modal', function() {
                $('.modal-body form')[0].reset();
                @if (Auth::user()->level_id != 0)
                    $('#Companies').selectpicker('val', ['{{ Auth::user()->company_id }}']);
                @else
                    $('#Companies').selectpicker('val', '');
                @endif
                $('input[name="_method"]').remove();
                resetPasswordVisibility(this);
            });
            $('#basicModal').on('show.bs.modal', function() {
                resetPasswordVisibility(this);
            });
            $('#Companies').selectpicker({
                liveSearch: true,
                header: "Select Companies",
                title: "Select Companies",
            });
            $('#FormServer').on('submit', function(e) {
                e.preventDefault();
                let formData = new FormData($(this)[0]);
                formData.append('user_id', "{{ Auth::user()->id }}");
                $.ajax({
                    url: getUrl(),
                    type: getMethod(),
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: "JSON",
                    success: function(response) {
                        $('#basicModal').modal('hide');
                        notification(response.status, response.message);
                        Table.ajax.reload(null, false);
                    },
                    error: function(res) {
                        let fields = res.responseJSON.errors;
                        $.each(fields, function(i, val) {
                            $.each(val, function(idx, value) {
                                notification(res.responseJSON.status,
                                    value);
                            })
                        })
                    }
                })
            })
        })
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
                                    <h5 class="card-title">Server Table</h5>
                                    @if(Auth::user()->level_id == 0)
                                    <button class="btn btn-sm btn-primary float-right" onclick="create()"><i
                                            class="fas fa-plus me-1"></i> Server</button>
                                    @endif
                                    <div class="table-responsive">
                                        <table class="table table-striped table-bordered" id="daTable">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>NAME</th>
                                                    <th>HOST</th>
                                                    <th>PORT</th>
                                                    <th>USERNAME</th>
                                                    <th>ACTION</th>
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
    <!-- Modal -->
    <div class="modal fade" id="basicModal">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Modal</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="FormServer" action="" method="post" enctype="multipart/form-data">
                        <div class="form-group row">
                            <div class="col-md-6">
                                <label for="Name">Name*</label>
                                <input type="text" id="Name" class="form-control" name="name" placeholder="Name">
                            </div>
                            <div class="col-md-6">
                                <label for="Host">Host*</label>
                                <input type="text" id="Host" class="form-control" name="host" placeholder="Host">
                            </div>
                        </div>
                        <div class="form-group row">
                            <div class="col-md-6">
                                <label for="Port">Port*</label>
                                <input type="number" id="Port" class="form-control" name="port" placeholder="Port">
                            </div>
                            <div class="col-md-6">
                                <label for="Username">Username</label>
                                <input type="text" id="Username" class="form-control" name="username"
                                    placeholder="Username">
                            </div>
                        </div>
                        <div class="form-group row password">
                            <div class="col-md-6">
                                <label for="Password">Password</label>
                                <div class="input-group mb-3">
                                    <input type="password" id="Password" class="form-control" name="password"
                                        placeholder="******">
                                    <div class="input-group-append">
                                        <button onclick="showPassword(this)" class="btn btn-outline-dark" type="button" aria-label="Show password"><i
                                                class="fa fa-eye" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="Companies">Companies*</label>
                                <select name="companies[]" id="Companies" class="form-control" multiple required>
                                    @foreach ($companies as $item)
                                        <option value="{{ $item->id }}" {{ Auth::user()->level_id != 0 ? 'selected' : '' }}>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary text-white" data-dismiss="modal">Close <i
                            class="fa-solid fa-xmark"></i></button>
                    <button type="submit" class="btn btn-success text-white">Save <i
                            class="fa-solid fa-floppy-disk"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
