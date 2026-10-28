<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\Auth;
use App\Security\SecurityHeaders;
use Throwable;

/** Noyau HTTP : session → langue → routage → gestion d'erreurs → en-têtes de sécurité. */
final class App
{
    private const ERROR_PAGES = [401, 403, 404, 405, 419, 500];

    public function __construct(private Container $container)
    {
    }

    public function run(): void
    {
        $config = $this->container->get(Config::class);
        $this->handle(Request::fromGlobals((string) $config->get('app.base_path', '')))->send();
    }

    public function handle(Request $request): Response
    {
        $this->container->put(Request::class, $request);
        $config = $this->container->get(Config::class);
        try {
            $session = $this->container->get(Session::class);
            $session->start($request->isSecure());
            $user = $this->container->get(Auth::class)->user();
            $this->container->get(I18n::class)->setLocale(
                (string) ($session->get('locale') ?? $user?->locale ?? $config->get('app.locale', 'fr'))
            );
            $response = $this->container->get(Router::class)->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status);
        } catch (Throwable $e) {
            $this->container->get(Logger::class)->error($e->getMessage(), [
                'exception' => $e,
                'method' => $request->method,
                'path' => $request->path,
            ]);
            $response = $this->error($request, 500, $config->get('app.debug') ? $e : null);
        }
        return SecurityHeaders::apply($response, $request->isSecure());
    }

    private function error(Request $request, int $status, ?Throwable $debug = null): Response
    {
        $page = in_array($status, self::ERROR_PAGES, true) ? $status : 500;
        if ($request->wantsJson()) {
            return Response::json(['error' => t("errors.{$page}.title")], $status);
        }
        try {
            $html = $this->container->get(View::class)->render('errors/error', [
                'title' => t("errors.{$page}.title"),
                'status' => $status,
                'message' => t("errors.{$page}.text"),
                'debug' => $debug === null ? null : (string) $debug,
            ], 'minimal');
        } catch (Throwable) {
            $html = '<!doctype html><title>' . $status . '</title><h1>' . $status . '</h1>';
        }
        return Response::html($html, $status);
    }
}
