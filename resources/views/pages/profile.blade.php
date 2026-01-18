@extends('layouts.default')
@section('title')
    {{ $title }}
@endsection
@push('css')
    <link rel="stylesheet" href="{{ asset('assets/plugins/toastr/css/toastr.min.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('assets/plugins/toastr/js/toastr.min.js') }}"></script>
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
                });
            } else if (status == 'success') {
                toastr.success(message, status.toUpperCase(), {
                    closeButton: 1,
                    showDuration: "300",
                    hideDuration: "1000",
                    showMethod: "fadeIn",
                    hideMethod: "fadeOut",
                    timeOut: 5e3,
                });
            }
        }

        $(document).ready(function() {
            // Preview photo before upload
            $('#photo').on('change', function() {
                const file = this.files[0];
                if (file) {
                    // Check file size (1MB = 1048576 bytes)
                    if (file.size > 1048576) {
                        notification('error', 'Photo size must be less than 1MB');
                        $(this).val('');
                        $('.custom-file-label').html('Choose photo');
                        return;
                    }
                    
                    // Update label with filename
                    $('.custom-file-label').html(file.name);
                    
                    // Preview image
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#photoPreview').attr('src', e.target.result);
                    }
                    reader.readAsDataURL(file);
                }
            });

            $('#FormProfile').on('submit', function(e) {
                e.preventDefault();
                let formData = new FormData($(this)[0]);
                
                $.ajax({
                    url: "{{ route('profile.update') }}",
                    type: "POST",
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: "JSON",
                    beforeSend: function() {
                        $('button[type="submit"]').prop('disabled', true);
                        $('button[type="submit"]').html('<i class="fa fa-spinner fa-spin"></i> Saving...');
                    },
                    success: function(response) {
                        notification(response.status, response.message);
                        // Clear password fields
                        $('#current_password').val('');
                        $('#new_password').val('');
                        $('#new_password_confirmation').val('');
                        // Reload page after 1 second to show updated photo
                        if ($('#photo').val()) {
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        }
                    },
                    error: function(res) {
                        if (res.status === 400 && res.responseJSON.fields) {
                            let fields = res.responseJSON.fields;
                            $.each(fields, function(i, val) {
                                notification('error', val[0]);
                            });
                        } else {
                            notification('error', res.responseJSON.message || 'An error occurred');
                        }
                    },
                    complete: function() {
                        $('button[type="submit"]').prop('disabled', false);
                        $('button[type="submit"]').html('Save Changes <i class="fa fa-save"></i>');
                    }
                });
            });
        });
    </script>
@endpush

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fa fa-user"></i> My Profile</h5>
                    <span class="d-block m-t-5">Update your profile information and password</span>
                </div>
                <div class="card-block">
                    <form id="FormProfile">
                        @csrf
                        <div class="row">
                            <!-- Profile Information -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6><i class="fa fa-info-circle"></i> Profile Information</h6>
                                    </div>
                                    <div class="card-block">
                                        <div class="form-group text-center">
                                            <label class="col-form-label">Profile Photo</label>
                                            <div class="mb-3">
                                                <img id="photoPreview" 
                                                     src="{{ $user->photo ? asset('dist/profiles/'.$user->photo) : asset('dist/images/default-avatar.png') }}" 
                                                     class="img-radius img-thumbnail" 
                                                     alt="Profile Photo" 
                                                     style="width: 150px; height: 150px; object-fit: cover;">
                                            </div>
                                            <div class="custom-file">
                                                <input type="file" name="photo" id="photo" class="custom-file-input" accept="image/*">
                                                <label class="custom-file-label" for="photo">Choose photo</label>
                                            </div>
                                            <small class="form-text text-muted">Max size: 1MB (JPEG, PNG, JPG, GIF)</small>
                                        </div>
                                        <div class="form-group">
                                            <label for="name" class="col-form-label">Name <span class="text-danger">*</span></label>
                                            <input type="text" name="name" id="name" class="form-control" 
                                                   value="{{ $user->name }}" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="email" class="col-form-label">Email <span class="text-danger">*</span></label>
                                            <input type="email" name="email" id="email" class="form-control" 
                                                   value="{{ $user->email }}" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="col-form-label">Position</label>
                                            <input type="text" class="form-control" 
                                                   value="{{ $user->position->name ?? '-' }}" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label class="col-form-label">Company</label>
                                            <input type="text" class="form-control" 
                                                   value="{{ $user->company->name ?? '-' }}" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label class="col-form-label">Level</label>
                                            <input type="text" class="form-control" 
                                                   value="{{ $user->level->name ?? '-' }}" disabled>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Change Password -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6><i class="fa fa-lock"></i> Change Password</h6>
                                    </div>
                                    <div class="card-block">
                                        <div class="alert alert-info">
                                            <i class="fa fa-info-circle"></i> Leave password fields empty if you don't want to change your password.
                                        </div>
                                        <div class="form-group">
                                            <label for="current_password" class="col-form-label">Current Password</label>
                                            <input type="password" name="current_password" id="current_password" 
                                                   class="form-control" placeholder="Enter current password">
                                        </div>
                                        <div class="form-group">
                                            <label for="new_password" class="col-form-label">New Password</label>
                                            <input type="password" name="new_password" id="new_password" 
                                                   class="form-control" placeholder="Enter new password (min. 6 characters)">
                                            <small class="form-text text-muted">Minimum 6 characters</small>
                                        </div>
                                        <div class="form-group">
                                            <label for="new_password_confirmation" class="col-form-label">Confirm New Password</label>
                                            <input type="password" name="new_password_confirmation" id="new_password_confirmation" 
                                                   class="form-control" placeholder="Confirm new password">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group text-right">
                                    <button type="submit" class="btn btn-primary btn-round">
                                        Save Changes <i class="fa fa-save"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
