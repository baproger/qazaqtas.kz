<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SetCurrentCompany;
use App\Http\Middleware\SetLocale;
use App\Models\ErrorLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Публичная витрина: те же middleware, что и у ERP, но без auth.
        then: fn () => Route::middleware('web')->group(__DIR__.'/../routes/site.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetLocale runs after StartSession (session available) but before
        // controllers, so localized stage names use the correct locale.
        $middleware->web(append: [
            SecureHeaders::class,
            SetLocale::class,
            SetCurrentCompany::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Журнал ошибок (Управление → Ошибки): исключения — через report,
        // HTTP-ошибки (404/403/419/429), которые Laravel не репортит, — на
        // ответе. ErrorLog сам никогда не бросает.
        $exceptions->report(fn (Throwable $e) => ErrorLog::fromThrowable($e, request()));
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface) {
                ErrorLog::fromThrowable($e, $request);
            }

            return $response;
        });
    })->create();
