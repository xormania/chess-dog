<?php

declare(strict_types=1);

namespace App\Archive\Model;

/** Server-only authority. Never construct this from a form, URL or browser header. */
final readonly class ArchiveContext
{
    public function __construct(
        public string $workspaceId,
        #[\SensitiveParameter] private string $apiToken,
    ) {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $workspaceId) || 'public' === $workspaceId
            || '' === $apiToken || preg_match('/\s/', $apiToken)) {
            throw new \InvalidArgumentException('Invalid server archive context.');
        }
    }

    public function authorizationHeader(): string
    {
        return 'Bearer '.$this->apiToken;
    }

    public function __debugInfo(): array
    {
        return ['workspaceId' => $this->workspaceId];
    }
}
