<?php
return [
    'menu' => [
        [
            'title' => 'Company',
            'icon' => 'fas fa-building',
            'route_name' => 'admin.company.index'
        ],
        [
            'title' => 'Server',
            'icon' => 'fas fa-server',
            'route_name' => 'admin.server.index'
        ],
        [
            'title' => 'Account',
            'icon' => 'fas fa-user',
            'route_name' => 'admin.user.index'
        ],
        [
            'title' => 'Master Data',
            'icon' => 'fas fa-sitemap',
            'sub_menu' =>
            [
                [
                    'title' => 'Level',
                    'route_name' => 'admin.level.index'
                ],
                [
                    'title' => 'User',
                    'route_name' => 'admin.user.index'
                ]
            ]
        ],
        [
            'title' => 'Activity Log',
            'icon' => 'ti-receipt',
            'route_name' => 'admin.log-activity.index'
        ]
    ],
];
