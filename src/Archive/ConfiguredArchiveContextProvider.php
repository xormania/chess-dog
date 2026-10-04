<?php

declare(strict_types=1);

namespace App\Archive;

use App\Archive\Model\ArchiveContext;

/** Single operator/local installation only; this is not application user authentication. */
final readonly class ConfiguredArchiveContextProvider implements ArchiveContextProvider
{
    public function __construct(
        private string $workspaceId,
        #[\SensitiveParameter] private string $apiToken,
    ) {
    }

    public function current(): ArchiveContext
    {
        try {
            return new ArchiveContext($this->workspaceId, $this->apiToken);
        } catch (\InvalidArgumentException) {
            throw new ArchiveUnavailable(503);
        }
    }

    public function __debugInfo(): array
    {
        return ['workspaceId' => $this->workspaceId];
    }
}
