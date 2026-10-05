<?php

declare(strict_types=1);

namespace App\Archive\Model;

use Symfony\Component\HttpFoundation\Cookie;

/** Cookie must be attached to an HTTP response, never serialized into Live props or JSON. */
final readonly class AuthorizedSubscription
{
    /** @param list<string> $topics @param list<EventCursor> $cursors */
    public function __construct(
        public string $workspaceId,
        public string $publicUrl,
        public array $topics,
        public array $cursors,
        #[\SensitiveParameter] private Cookie $cookie,
    ) {
    }

    /** The only configuration suitable for browser-visible subscription wiring. */
    public function connectionOptions(): array
    {
        return ['url' => $this->publicUrl, 'topics' => $this->topics, 'withCredentials' => true];
    }

    public function cookie(): Cookie
    {
        return $this->cookie;
    }

    public function __debugInfo(): array
    {
        return $this->connectionOptions();
    }
}
