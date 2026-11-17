<html class="h-100" lang="en" dir="ltr">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>403 | No Access</title>
    <!-- Favicon icon -->
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('assets/images/favicon.png') }}">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">

</head>

<body class="h-100" data-theme-version="light" data-layout="vertical" data-nav-headerbg="color_1"
    data-headerbg="color_1" data-sidebar-style="full" data-sibebarbg="color_1" data-sidebar-position="static"
    data-header-position="static" data-container="wide" direction="ltr">

    <!--*******************
        Preloader start
    ********************-->
    <div id="preloader" style="display: none;">
        <div class="loader">
            <svg class="circular" viewBox="25 25 50 50">
                <circle class="path" cx="50" cy="50" r="20" fill="none" stroke-width="3"
                    stroke-miterlimit="10"></circle>
            </svg>
        </div>
    </div>
    <!--*******************
        Preloader end
    ********************-->


    <div class="login-form-bg h-100">
        <div class="container h-100">
            <div class="row justify-content-center h-100">
                <div class="col-xl-6">
                    <div class="error-content">
                        <div class="card mb-0">
                            <div class="card-body text-center">
                                <h1 class="error-text text-primary">403</h1>
                                <h4 class="mt-4"><i class="fa fa-thumbs-down text-danger"></i> Bad Request</h4>
                                <p>Your Role Level doesnt have access to this page.</p>
                                <form class="mt-5 mb-5">

                                    <div class="text-center mb-4 mt-4"><a href="{{ route('auth.dashboard') }}"
                                            class="btn btn-primary">Go
                                            to Homepage</a>
                                    </div>
                                </form>
                                <div class="text-center">
                                    <p>Copyright © Designed by <a href="javascript:void(0)">PTT UniGuard</a>
                                        {{ date('Y') }}
                                    </p>
                                    <ul class="list-inline">
                                        <li class="list-inline-item"><a href="javascript:void()"
                                                class="btn btn-facebook"><i class="fa-brands fa-facebook"></i></a>
                                        </li>
                                        <li class="list-inline-item"><a href="javascript:void()"
                                                class="btn btn-twitter"><i class="fa-brands fa-twitter"></i></a>
                                        </li>
                                        <li class="list-inline-item"><a href="javascript:void()"
                                                class="btn btn-linkedin"><i class="fa-brands fa-linkedin"></i></a>
                                        </li>
                                        <li class="list-inline-item"><a href="javascript:void()"
                                                class="btn btn-google-plus"><i class="fa-brands fa-google-plus"></i></a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--**********************************
        Scripts
    ***********************************-->
    <script src="{{ asset('assets/icons/font-awesome/js/all.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/common/common.min.js') }}"></script>
    <script src="{{ asset('assets/js/custom.min.js') }}"></script>
    <script src="{{ asset('assets/js/settings.js') }}"></script>
    <script src="{{ asset('assets/js/gleek.js') }}"></script>
    <script src="{{ asset('assets/js/styleSwitcher.js') }}"></script>
</body>

</html>
