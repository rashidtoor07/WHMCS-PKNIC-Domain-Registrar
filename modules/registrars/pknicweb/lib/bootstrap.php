<?php

declare(strict_types=1);

foreach ([
    'Exceptions', 'OptionChoice', 'Response', 'HttpTransport', 'CurlTransport', 'Config', 'MapLoader', 'Selector',
    'Validator', 'PknicClient', 'PknicActions', 'JobQueue', 'Settings', 'Notifier', 'JobProcessor', 'Runner',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
