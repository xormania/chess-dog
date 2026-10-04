<?php

declare(strict_types=1);

namespace App\Archive\Model;

final readonly class ValidatedEvent
{
    public function __construct(
        public string $archiveId,
        public string $eventId,
        public string $kind,
        public int $resourceId,
        public int $revision,
        public ?int $runId,
    ) {
    }
}
