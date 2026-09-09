<?php

return [

    /*
    | Telegram bot token stays in encrypted telegram_bot_settings (not duplicated here).
    | Mini App settings are non-secret product configuration.
    */

    'webapp' => [
        'path' => '/telegram/webapp',
        'short_name' => env('TELEGRAM_WEBAPP_SHORT_NAME', 'app'),
        'menu_button_text' => 'Open LAVR',
        'auth_max_age' => (int) env('TELEGRAM_WEBAPP_AUTH_MAX_AGE', 86400),
        'rate_limit_per_minute' => (int) env('TELEGRAM_WEBAPP_RATE_LIMIT', 20),
    ],

];
