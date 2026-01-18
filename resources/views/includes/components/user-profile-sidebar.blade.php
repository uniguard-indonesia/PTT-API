<div class="">
    <div class="main-menu-header">
        <img class="img-80 img-radius" src="{{ asset('dist/profiles/'.Auth::user()->photo) }}" alt="User-Profile-Image">
        <div class="user-details">
            <span id="more-details">{{ Auth::user()->name }}<i class="fa fa-caret-down"></i></span>
        </div>
    </div>
    <div class="main-menu-content">
        <ul>
            <li class="more-details">
                <a href="{{ route('profile') }}"><i class="ti-user"></i>View Profile</a>
                <a href="{{ route('auth.logout') }}"><i class="ti-layout-sidebar-left"></i>Logout</a>
            </li>
        </ul>
    </div>
</div>
