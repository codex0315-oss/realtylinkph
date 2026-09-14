<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    // Register broadcasting auth (/broadcasting/auth) behind Sanctum so the SPA's
    // Bearer-token Echo requests authenticate (the default web guard can't read tokens).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent.verified' => \App\Http\Middleware\EnsureAgentIsVerified::class,
            'admin'          => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'role'           => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'     => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Helpers\ApiResponse::error('Unauthenticated.', [], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Helpers\ApiResponse::error('Forbidden.', [], 403);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Helpers\ApiResponse::error('Resource not found.', [], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Helpers\ApiResponse::error('Validation failed.', $e->errors(), 422);
            }
        });

        // A body over post_max_size never reaches validation — PHP throws away
        // $_POST and $_FILES, and Laravel's own 413 carries an empty message
        // plus a debug payload. Give the client something it can actually show.
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return \App\Helpers\ApiResponse::error(
                    'That upload is too large. Please use an image under 10 MB.',
                    ['photo' => ['That upload is too large. Please use an image under 10 MB.']],
                    413
                );
            }
        });
    })->create();
