<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\Auth;

/**
 * Routage déclaratif (config/routes.php). Chaque route est protégée par défaut :
 * authentification requise, permission vérifiée côté serveur, CSRF sur toute méthode non-GET.
 */
final class Router
{
    /** @var list<array{method: string, path: string, regex: string, handler: array{0: class-string, 1: string}, perm: ?string, public: bool}> */
    private array $routes = [];

    public function __construct(private Container $container, private Csrf $csrf, private Session $session)
    {
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $path, array $handler, ?string $perm = null, bool $public = false): void
    {
        $this->add('GET', $path, $handler, $perm, $public);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $path, array $handler, ?string $perm = null, bool $public = false): void
    {
        $this->add('POST', $path, $handler, $perm, $public);
    }

    public function has(string $method, string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return true;
            }
        }
        return false;
    }

    /** @param array{0: class-string, 1: string} $handler */
    private function add(string $method, string $path, array $handler, ?string $perm, bool $public): void
    {
        $regex = '#^' . preg_replace_callback(
            '#\{([a-z_]+)(?::([^}]+))?\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $path,
        ) . '$#';
        $this->routes[] = compact('method', 'path', 'regex', 'handler', 'perm', 'public');
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return $this->run($route, $request, $params);
        }
        throw new HttpException($pathMatched ? 405 : 404);
    }

    /**
     * @param array{handler: array{0: class-string, 1: string}, perm: ?string, public: bool} $route
     * @param array<string, string> $params
     */
    private function run(array $route, Request $request, array $params): Response
    {
        $user = $this->container->get(Auth::class)->user();
        if (!$route['public'] && $user === null) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            if ($request->method === 'GET') {
                $this->session->set('_intended', $request->fullPath());
            }
            return Response::redirect(url('/login'));
        }
        // Mot de passe provisoire : seul l'écran de changement (et la déconnexion) reste accessible.
        if ($user !== null && $user->mustChangePassword && !in_array($request->path, ['/account/password', '/logout', '/locale'], true)) {
            return Response::redirect(url('/account/password'));
        }
        if ($route['perm'] !== null && !($user?->can($route['perm']) ?? false)) {
            throw new HttpException(403);
        }
        if (!in_array($request->method, ['GET', 'HEAD'], true) && !$this->csrf->verify($request)) {
            throw new HttpException(419);
        }
        [$class, $action] = $route['handler'];
        return $this->container->get($class)->{$action}($request, ...array_values($params));
    }
}
