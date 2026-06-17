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

        function getUrl() {
            return url;
        }

        function getMethod() {
            return method;
        }
        var Table = $('#daTable').DataTable({
            ajax: "{{ route('admin.user.index') }}",
            processing: true,
            serverSide: true,
            responsive: true,
            columns: [{
                    data: 'DT_RowIndex'
                },
                {
                    data: 'created_at'
                },
                {
                    data: 'company'
                },
                {
                    data: 'level'
                },
                {
                    data: 'name'
                },
                {
                    data: 'email'
                },
                {
                    data: 'action'
                }
            ],
            columnDefs: [{
                targets: [3],
                className: 'dt-center'
            }],
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
            url = "{{ route('admin.user.store') }}";
            method = 'POST';
            $('.modal-header h5').html("Create User");
            $('#basicModal').modal('show');
        }

        function edit(id) {
            let editUrl = "{{ route('admin.user.edit', ':id') }}";
            editUrl = editUrl.replace(':id', id);
            url = "{{ route('admin.user.update', ':id') }}";
            url = url.replace(':id', id);
            method = "POST";
            $('.modal-body form').append('<input type="hidden" name="_method" value="PUT" />');
            $('.password').css('display', 'none');
            $('.password').find('input').prop('required', false);
            $.get(editUrl, function(res) {
                $('.modal-header h5').html("Edit User");
                $('input[name="name"]').val(res.data.name);
                $('input[name="email"]').val(res.data.email);
                $('select[name="level_id"]').selectpicker('val', res.data.level_id);
                $('select[name="company_id"]').selectpicker('val', res.data.company_id);
                $('#preview').attr('src', "{{ asset('dist/profiles') }}"+'/'+res.data.photo);
                $('#basicModal').modal('show');
            })
        }

        function resetPassword(id) {
            method = "PUT";
            $('#FormReset')[0].reset();
            let resetUrl = "{{ route('admin.user.reset', ':id') }}";
            url = resetUrl.replace(':id', id);
            $('.modal-header h5').html("Reset Password Akun");
            $('#resetModal').modal('show');
        }

        function regenerateCert(id) {
            let regenUrl = "{{ route('admin.user.regenerate-certificate', ':id') }}";
            regenUrl = regenUrl.replace(':id', id);
            swal({
                title: 'Regenerate Certificate?',
                text: "This will create a new certificate for the user and register it to Mumble.",
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Regenerate',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#ff9800',
                reverseButtons: true
            }).then((isConfirm) => {
                if (isConfirm.value) {
                    swal({
                        title: 'Please Wait...',
                        text: 'Generating certificate...',
                        allowOutsideClick: false,
                        onOpen: () => {
                            swal.showLoading();
                        }
                    });
                    $.ajax({
                        url: regenUrl,
                        type: "POST",
                        dataType: "JSON",
                        success: function(response) {
                            swal.close();
                            notification(response.status, response.message);
                            Table.ajax.reload(null, false);
                        },
                        error: function(res) {
                            swal.close();
                            notification(res.responseJSON.status, res.responseJSON.message);
                        }
                    })
                }
            })
        }

        function destroy(id) {
            let deleteUrl = "{{ route('admin.user.destroy', ':id') }}";
            deleteUrl = deleteUrl.replace(':id', id);
            swal({
                title: 'Hapus data ini?',
                text: "Data ini tidak akan bisa dikembalikan!",
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Hapus!',
                cancelButtonText: 'Batal',
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
            let type = $(that).closest('.input-group').find('input').attr('type');
            if (type == 'password') {
                $(that).closest('.input-group').find('input').attr('type', 'text');
                $(that).html('<i class="ti-eye"></i>');
            } else {
                $(that).closest('.input-group').find('input').attr('type', 'password');
                $(that).html('<i class="fas fa-eye-slash"></i>');
            }
        }
        $(document).ready(function() {
            $('#basicModal').on('hide.bs.modal', function() {
                $('.modal-body form')[0].reset();
                $('#preview').attr('src', "{{ asset('dist/profiles/default.jpg') }}");
                $('.password').css('display', 'block');
                $('.password').find('input').prop('required', true);
                $('#Company, #Level').selectpicker('val', '');
                $('input[name="_method"]').remove();
            })
            $('#Level').selectpicker({
                liveSearch: true,
                header: "Select Level",
                title: "Select Level",
            });
            $('#Company').selectpicker({
                liveSearch: true,
                header: "Select Company",
                title: "Select Company",
            });
            $('#ProfilePhoto').change(function() {
                const file = this.files[0];
                if (file) {
                    let reader = new FileReader();
                    reader.onload = function(event) {
                        $('#preview').attr('src', event.target.result);
                    }
                    reader.readAsDataURL(file);
                }
            });
            $('#FormAccount').on('submit', function(e) {
                e.preventDefault();
                let formData = new FormData($(this)[0]);
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
                        let fields = res.responseJSON.fields;
                        $.each(fields, function(i, val) {
                            $.each(val, function(idx, value) {
                                notification(response.responseJSON.status,
                                    value);
                            })
                        })
                    }
                })
            })
            $('#FormReset').on('submit', function(e) {
                e.preventDefault();
                $.ajax({
                    url: getUrl(),
                    type: getMethod(),
                    data: $(this).serialize(),
                    dataType: "JSON",
                    success: function(response) {
                        $('#resetModal').modal('hide');
                        notification(response.status, response.message);
                        Table.ajax.reload(null, false);
                    },
                    error: function(res) {
                        let fields = res.responseJSON.fields;
                        $.each(fields, function(i, val) {
                            $.each(val, function(idx, value) {
                                notification(response.responseJSON.status,
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
                                    <h5 class="card-title">Account Table</h5>
                                    <button class="btn btn-sm btn-primary float-right" onclick="create()"><i
                                            class="ti-plus"></i> Account</button>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-bordered" id="daTable">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>CREATED AT</th>
                                                    <th>COMPANY</th>
                                                    <th>LEVEL</th>
                                                    <th>NAME</th>
                                                    <th>EMAIL</th>
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
                    <form id="FormAccount" action="" method="post" enctype="multipart/form-data">
                        <div class="form-group row">
                            <div class="col-md-6">
                                <label for="Company" class="col-form-label">Company*</label>
                                <select name="company_id" id="Company" class="form-control">
                                    @foreach ($companies as $item)
                                        <option value="{{ $item->id }}" {{ Auth::user()->level_id != 0?'selected':'' }}>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="Level" class="col-form-label">Level*</label>
                                <select name="level_id" id="Level" class="form-control">
                                    @foreach ($levels as $item)
                                        <option value="{{ $item->id }}">{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="form-group row">
                            <div class="col-md-6">
                                <label for="Name">Name*</label>
                                <input type="text" id="Name" class="form-control" name="name" placeholder="Name"
                                    required>
                            </div>
                            <div class="col-md-6">
                                <label for="Email">Email*</label>
                                <input type="email" id="Email" class="form-control" name="email"
                                    placeholder="account@email.com" required>
                            </div>
                        </div>
                        <div class="form-group row">
                            <div class="col-md-6">
                                <label for="ProfilePhoto">Photo Profile</label>
                                <div class="input-group">
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" name="photo" id="ProfilePhoto">
                                        <label class="custom-file-label">Upload Photo</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="password">
                                    <label for="Password">Password*</label>
                                    <div class="input-group mb-3">
                                        <input type="password" id="Password" class="form-control" name="password"
                                            placeholder="******" required>
                                        <div class="input-group-append">
                                            <button onclick="showPassword(this)" class="btn btn-outline-dark" type="button"><i
                                                    class="ti-eye"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <img id="preview" src="{{ asset('dist/profiles/default.jpg') }}" alt="Profile Photo" class="img-thumbnail" width="128">
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary text-white" data-dismiss="modal">Close <i
                            class="ti-close"></i></button>
                    <button type="submit" class="btn btn-success text-white">Save <i
                            class="ti-save"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal -->
    <div class="modal fade" id="resetModal">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Modal</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="FormReset" action="" method="post">
                        <div class="form-group password">
                            <label for="NewPassword">New Password</label>
                            <div class="input-group mb-3">
                                <input type="password" id="NewPassword" class="form-control" name="new_password"
                                    placeholder="******" required>
                                <div class="input-group-append">
                                    <button onclick="showPassword(this)" class="btn btn-outline-dark" type="button"><i
                                            class="fa-regular fa-eye-slash"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="form-group password">
                            <label for="ConfirmPassword">Confirm Password</label>
                            <div class="input-group mb-3">
                                <input type="password" id="ConfirmPassword" class="form-control" name="confirm_password"
                                    placeholder="******" required>
                                <div class="input-group-append">
                                    <button onclick="showPassword(this)" class="btn btn-outline-dark" type="button"><i
                                            class="fa-regular fa-eye-slash"></i></button>
                                </div>
                            </div>
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary text-white" data-dismiss="modal">Tutup <i
                            class="fa-solid fa-xmark"></i></button>
                    <button type="submit" class="btn btn-success text-white">Simpan <i
                            class="fa-solid fa-floppy-disk"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
