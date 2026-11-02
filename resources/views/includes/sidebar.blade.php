<nav class="pcoded-navbar">
    <div class="sidebar_toggle"><a href="#"><i class="icon-close icons"></i></a></div>
    <div class="pcoded-inner-navbar main-menu">
        @include('includes.components.user-profile-sidebar')
        <div class="pcoded-navigation-label">Navigation</div>
        @php
            // echo '<pre>', var_dump(collect(config('sidebar.menu'))->where('title', 'Master Data')), '</pre>';
            $menus = collect(config('sidebar.menu'))
                ->when((int) Auth::user()->level_id !== 0, function($q){
                    return $q->whereIn('title', explode(',', Auth::user()->level->menu ?? ''))
                             ->where('title', '<>', 'Company');
                }, function($q){
                    return $q->where('title', '<>', 'Account');
                })
                ->all();
        @endphp
        <ul class="pcoded-item pcoded-left-item">
            <li class="{{ Route::is('auth.dashboard')?'active':'' }}">
                <a href="{{ route('auth.dashboard') }}" class="waves-effect waves-dark">
                    <span class="pcoded-micon"><i class="ti-home"></i><b>D</b></span>
                    <span class="pcoded-mtext">Dashboard</span>
                    <span class="pcoded-mcaret"></span>
                </a>
            </li>
        </ul>
        @foreach ($menus as $key => $menu)
        @php
            if (isset($menu['route_name'])) {
                $active = request()->routeIs($menu['route_name']) ? 'active' : '';
            } else {
                if (isset($menu['sub_menu'])) {
                    $active = '';
                    foreach ($menu['sub_menu'] as $idx => $item) {
                        if (request()->routeIs($item['route_name'])) {
                            $active = 'active';
                            break;
                        }
                    }
                }
            }
        @endphp
        <ul class="pcoded-item pcoded-left-item">
            <li class="{{ (isset($menu['route_name'])?'':'pcoded-hasmenu pcoded-trigger ').$active }}">
                <a href="{{ isset($menu['route_name'])?route($menu['route_name']):'javascript:void(0)' }}" class="waves-effect waves-dark">
                    <span class="pcoded-micon"><i class="{{ $menu['icon'] }} "></i><b>D</b></span>
                    <span class="pcoded-mtext">{{ $menu['title'] }}</span>
                    <span class="pcoded-mcaret"></span>
                </a>
                @if (isset($menu['sub_menu']))
                    <ul class="pcoded-submenu">
                        @foreach ($menu['sub_menu'] as $idx => $item)
                            <li class="{{ request()->routeIs($item['route_name']) ? 'active' : '' }}">
                                <a href="{{ route($item['route_name']) }}" class="waves-effect waves-dark">
                                    <span class="pcoded-micon"><i class="ti-angle-right"></i></span>
                                    <span class="pcoded-mtext">{{ $item['title'] }}</span>
                                    <span class="pcoded-mcaret"></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        </ul>
        @endforeach
    </div>
</nav>
