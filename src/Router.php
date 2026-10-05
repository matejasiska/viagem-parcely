<?php

declare(strict_types=1);

namespace Katastr;

final class Router
{
    /** @var list<array{string, callable}> */
    private array $routes = [];

    /**
     * Vzor je regulární výraz bez ohraničení, například '/api/parcela/(?<id>\d+)'.
     * Obsluha dostane jedno pole: pojmenované skupiny z cesty a parametry z query stringu.
     * Při shodě jména má přednost cesta, aby query string nepřepsal třeba id z URL.
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = [$pattern, $handler];
    }

    public function dispatch(string $method, string $path, array $query): void
    {
        foreach ($this->routes as [$pattern, $handler]) {
            if (preg_match('#^' . $pattern . '$#', $path, $matches) !== 1) {
                continue;
            }

            if ($method !== 'GET') {
                Response::error(405, 'Tento endpoint podporuje jen GET.');

                return;
            }

            $handler(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY) + $query);

            return;
        }

        Response::error(404, 'Neznámý endpoint.');
    }
}
