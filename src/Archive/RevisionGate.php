<?php

declare(strict_types=1);

namespace App\Archive;

use App\Archive\Model\EventCursor;
use App\Archive\Model\ValidatedEvent;

/** Snapshots are authoritative. Events do not mutate cursor state or increment counters. */
final class RevisionGate
{
    public function action(ValidatedEvent $event, EventCursor $cursor): string
    {
        if ($event->kind !== $cursor->kind || $event->resourceId !== $cursor->resourceId) {
            throw new \InvalidArgumentException('Archive event and cursor differ.');
        }
        if ($event->archiveId !== $cursor->archiveId) {
            return 'refresh'; // Archive replacement invalidates cached resource identity.
        }
        if ($event->revision <= $cursor->revision) {
            return 'ignore';
        }

        // Both the next revision and a history gap require a new scoped SQL snapshot.
        return 'refresh';
    }

    public function onReconnect(): string
    {
        return 'refresh';
    }
}
