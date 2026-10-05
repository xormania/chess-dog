<?php

declare(strict_types=1);

namespace App\Archive;

/** Deliberately excludes upstream response bodies, URLs, credentials and exception chains. */
final class ArchiveUnavailable extends \RuntimeException
{
    public function __construct(public readonly int $statusCode)
    {
        parent::__construct(404 === $statusCode ? 'Archive resource not found.' : 'Archive request unavailable.');
    }
}
