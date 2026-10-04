<?php

declare(strict_types=1);

namespace App\Archive;

final class ArchiveUnavailable extends \RuntimeException
{
    public function __construct(public readonly int $status = 503)
    {
        parent::__construct(404 === $status ? 'This player is not in your archive yet.' : 'Your archive is temporarily unavailable.');
    }
}
