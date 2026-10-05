<?php

declare(strict_types=1);

namespace App\Archive;

use App\Archive\Model\ArchiveContext;

interface ArchiveContextProvider
{
    /** Resolve authority from trusted server configuration or an authenticated app principal. */
    public function current(): ArchiveContext;
}
