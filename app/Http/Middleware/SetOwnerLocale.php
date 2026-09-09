<?php

namespace App\Http\Middleware;

use App\Services\Locale\OwnerLocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetOwnerLocale
{
    public function __construct(
        private readonly OwnerLocaleResolver $locales,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->locales->interfaceLocale($request->user());
        app()->setLocale($locale->value);

        return $next($request);
    }
}
