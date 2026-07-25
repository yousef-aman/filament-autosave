<?php

return [
    'debounce' => 1500,

    // ->password() inputs are always dropped, regardless of this list.
    'except' => [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
    ],

    'show_timestamp' => true,

    'cache_ttl' => 24,
];
