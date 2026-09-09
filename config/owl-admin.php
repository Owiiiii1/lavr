<?php

return [

    'name' => env('OWL_ADMIN_BRAND', 'LAVR'),

    'brand_name' => env('OWL_ADMIN_BRAND', 'LAVR'),

    'logo_path' => env('OWL_ADMIN_LOGO', '/images/company-logo.svg'),

    'branding' => [
        'brand_name' => env('OWL_ADMIN_BRAND', 'LAVR'),
        'logo_path' => env('OWL_ADMIN_LOGO', '/images/company-logo.svg'),
        'tagline' => env('OWL_ADMIN_TAGLINE', 'Workspace'),
    ],

    'route_prefix' => env('OWL_ADMIN_ROUTE_PREFIX', ''),

    'login_path' => env('OWL_ADMIN_LOGIN_PATH', 'login'),

    'dashboard_route' => env('OWL_ADMIN_DASHBOARD_ROUTE', 'dashboard'),

    'email_verification' => filter_var(env('OWL_ADMIN_EMAIL_VERIFICATION', false), FILTER_VALIDATE_BOOL),

    /*
    | Use OwlSolutions\CustomAdminKit\Support\AdminRouteMiddleware::stack() in host routes.
    | Default: ['web', 'auth']. Adds 'verified' only when email_verification is true.
    */
    'middleware' => ['web', 'auth'],

    'locales' => ['en', 'ru', 'uk'],

];
