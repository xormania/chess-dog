<?php

declare(strict_types=1);

namespace App\Archive;

/** Logical topic identifiers are independent of the hub's network URL. */
final readonly class TopicNames
{
    public function __construct(private string $prefix)
    {
        $url = parse_url($prefix);
        if (false === $url || !in_array($url['scheme'] ?? null, ['https', 'http'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || preg_match('/[\s{}*]/', $prefix)) {
            throw new \InvalidArgumentException('Invalid archive topic prefix.');
        }
    }

    public function resource(string $workspace, string $kind, int $id): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $workspace) || 'public' === $workspace
            || !in_array($kind, ['jobs', 'runs'], true) || $id < 1) {
            throw new \InvalidArgumentException('Invalid archive topic resource.');
        }

        return rtrim($this->prefix, '/').'/workspaces/'.$workspace.'/'.$kind.'/'.$id;
    }
}
