<?php

use App\Http\Middleware\EnsureOwner;
use App\Http\Middleware\EnsureOwnerWorkspace;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectOwnerCabinetToWorkspace;
use App\Http\Middleware\RedirectOwnerFromUserWorkspace;
use App\Http\Middleware\SetOwnerLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetOwnerLocale::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->trimStrings(except: [
            'init_data',
        ]);

        $middleware->alias([
            'owner' => EnsureOwner::class,
            'owner.workspace' => EnsureOwnerWorkspace::class,
            'personal.workspace' => RedirectOwnerFromUserWorkspace::class,
            'cabinet.owner.redirect' => RedirectOwnerCabinetToWorkspace::class,
            'user.active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
