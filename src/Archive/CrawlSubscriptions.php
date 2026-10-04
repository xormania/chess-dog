<?php

declare(strict_types=1);

namespace App\Archive;

use App\Archive\Model\AuthorizedSubscription;
use App\Archive\Model\EventCursor;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubRegistry;

/** Grants exact resources only after a scoped API read; no workspace wildcard or publisher grant. */
final readonly class CrawlSubscriptions
{
    public function __construct(
        private CrawlClient $client,
        private ArchiveContextProvider $contexts,
        private TopicNames $topics,
        private HubRegistry $hubs,
        #[\SensitiveParameter] private string $subscriberSecret,
    ) {
    }

    public function forRun(Request $request, int $id): AuthorizedSubscription
    {
        $run = $this->client->run($id);

        // A run can contain arbitrarily many jobs. Do not enumerate them into a browser cookie.
        return $this->subscription($request, $run['workspace_id'], [EventCursor::fromSnapshot('runs', $run)]);
    }

    public function forJob(Request $request, int $id): AuthorizedSubscription
    {
        $job = $this->client->job($id);

        return $this->subscription($request, $job['workspace_id'], [EventCursor::fromSnapshot('jobs', $job)]);
    }

    private function subscription(Request $request, string $workspaceId, array $cursors): AuthorizedSubscription
    {
        $context = $this->contexts->current();
        try {
            if ($workspaceId !== $context->workspaceId || strlen($this->subscriberSecret) < 32) {
                throw new ArchiveUnavailable(503);
            }
            $hub = $this->hubs->getHub('crawl');
            $publicUrl = $hub->getPublicUrl();
            $url = parse_url($publicUrl);
            if (false === $url || !in_array($url['scheme'] ?? null, ['https', 'http'], true)
                || empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])
                || preg_match('/\s/', $publicUrl)) {
                throw new ArchiveUnavailable(503);
            }
            // This foundation intentionally uses a host-only cookie through the app's origin.
            // A same-origin reverse proxy avoids granting parent-domain cookies or CORS authority.
            $scheme = strtolower($url['scheme']);
            $port = $url['port'] ?? ('https' === $scheme ? 443 : 80);
            if ($scheme !== $request->getScheme() || strtolower($url['host']) !== $request->getHost()
                || $port !== (int) $request->getPort()) {
                throw new ArchiveUnavailable(503);
            }
            $topics = array_map(fn (EventCursor $cursor) => $this->topics->resource(
                $context->workspaceId, $cursor->kind, $cursor->resourceId,
            ), $cursors);
            $expires = new \DateTimeImmutable('+5 minutes');
            $token = $hub->getFactory()->create($topics, null, ['exp' => $expires, 'iat' => new \DateTimeImmutable()]);
            $cookie = Cookie::create('mercureAuthorization', $token, $expires, $url['path'] ?? '/', null,
                'https' === $url['scheme'], true, false, Cookie::SAMESITE_STRICT);

            return new AuthorizedSubscription($context->workspaceId, $publicUrl, $topics, $cursors, $cookie);
        } catch (ArchiveUnavailable $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ArchiveUnavailable(503);
        }
    }

    public function __debugInfo(): array
    {
        return ['hub' => 'crawl'];
    }
}
