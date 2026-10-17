<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function request_method_is(string $method): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === strtoupper($method);
}

function image_url(?string $path, string $fallback = 'assets/img/default.svg'): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return $fallback;
    }

    // Preserve remote image URLs, if a deployment uses them.
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $relativePath = ltrim(str_replace('\\', '/', $path), '/');
    $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    return is_file($localPath) ? $relativePath : $fallback;
}
