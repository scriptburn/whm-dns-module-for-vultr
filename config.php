<?php

return
    [
    'modules'             => [
        'vultr' => ['APP_KEY' => 'enter your api key here'],
    ],
    'email_on_error'      => 1,
    'error_email_address' => 'support@scriptburn.com',
    'in_use'              => 'vultr',
    'describe'            => [

        [
            'category'      => 'Whostmgr',
            'event'         => 'Accounts::Create',
            'stage'         => 'post',
            'exectype'      => 'script',
            'escalateprivs' => 1,
        ],
        [
            'blocking'      => 1,
            'category'      => 'Whostmgr',
            'event'         => 'Accounts::Remove',
            'stage'         => 'pre',
            'exectype'      => 'script',
            'escalateprivs' => 1,
        ],
        [
            'blocking' => 1,
            'category' => 'Whostmgr',
            'event'    => 'Domain::park',
            'stage'    => 'post',
            'exectype' => 'script',
        ],
        [
            'blocking' => 1,
            'category' => 'Whostmgr',
            'event'    => 'Domain::unpark',
            'stage'    => 'post',
            'exectype' => 'script',
        ],

        [
            'category'      => 'Cpanel',
            'event'         => 'Api2::SubDomain::addsubdomain',
            'stage'         => 'post',
            'exectype'      => 'script',
            'escalateprivs' => 1,

        ],
        [
            'category'      => 'Cpanel',
            'event'         => 'Api2::SubDomain::delsubdomain',
            'stage'         => 'post',
            'exectype'      => 'script',
            'escalateprivs' => 1,
        ],
        [
            'category' => 'Cpanel',
            'event'    => 'Api2::ZoneEdit::add_zone_record',
            'stage'    => 'post',
            'exectype' => 'script',
        ],

    ],
];
