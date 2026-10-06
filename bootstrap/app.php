<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tracker.auth' => \App\Http\Middleware\RequireTrackerUser::class,
        ]);
        $middleware->validateCsrfTokens(except: ['api/auth.php', 'api/data.php', 'api/attachments.php', 'api/project-edit.php', 'api/profile.php', 'api/backups.php']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Validation\ValidationException $error, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) return response()->json(['ok'=>false,'message'=>collect($error->errors())->flatten()->first(),'errors'=>$error->errors()],422);
        });
        $exceptions->render(function (\Illuminate\Database\QueryException $error, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                $duplicate = in_array((string)($error->errorInfo[0] ?? $error->getCode()), ['23505','23000'], true);
                return response()->json(['ok'=>false,'message'=>$duplicate ? 'This record conflicts with an existing record. Refresh and try again.' : 'The database request failed. Please try again or contact your administrator.'], $duplicate ? 409 : 500);
            }
        });
    })->create();
