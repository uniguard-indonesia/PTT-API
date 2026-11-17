<!-- Page-header start -->
<div class="page-header">
    <div class="page-block">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="page-header-title">
                    <h5 class="m-b-10">{{ empty($title) ? 'Error' : $title }}</h5>
                    {{-- <p class="m-b-0">Welcome to Material Able</p> --}}
                </div>
            </div>
            <div class="col-md-4">
                <ul class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="{{ route('auth.dashboard') }}"> <i class="fa fa-home"></i> </a>
                    </li>
                    @foreach (Request::segments() as $item)
                        <li class="breadcrumb-item">
                            <a href="#!">{{ $item === 'log-activity' ? 'Activity Log' : ucfirst($item) }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
<!-- Page-header end -->
