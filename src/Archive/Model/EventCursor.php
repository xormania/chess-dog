<?php

declare(strict_types=1);

namespace App\Archive\Model;

/** Explicit per-consumer state, never a mutable singleton shared across users. */
final readonly class EventCursor
{
    public function __construct(
        public string $archiveId,
        public string $kind,
        public int $resourceId,
        public int $revision,
    ) {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $archiveId) || !in_array($kind, ['runs', 'jobs'], true)
            || $resourceId < 1 || $revision < 0) {
            throw new \InvalidArgumentException('Invalid archive event cursor.');
        }
    }

    public static function fromSnapshot(string $kind, array $snapshot): self
    {
        if (!is_string($snapshot['archive_id'] ?? null) || !is_int($snapshot['id'] ?? null)
            || !is_int($snapshot['revision'] ?? null)) {
            throw new \InvalidArgumentException('Invalid archive snapshot.');
        }

        return new self($snapshot['archive_id'], $kind, $snapshot['id'], $snapshot['revision']);
    }
}
