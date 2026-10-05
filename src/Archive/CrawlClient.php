<?php

declare(strict_types=1);

namespace App\Archive;

use App\Archive\Model\ArchiveContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** JSON-only API boundary. No provider request, rendering, or implicit acquisition on reads. */
final readonly class CrawlClient
{
    private const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ArchiveContextProvider $contexts,
        private string $baseUrl,
    ) {
    }

    public function player(string $provider, string $username): array
    {
        return $this->read($this->playerPath($provider, $username));
    }

    public function games(string $provider, string $username, int $limit = 50, ?int $after = null): array
    {
        if ($limit < 1 || $limit > 100 || (null !== $after && $after < 0)) {
            throw new \InvalidArgumentException('Invalid archive page.');
        }

        return $this->read($this->playerPath($provider, $username).'/games', array_filter([
            'limit' => $limit, 'after' => $after,
        ], static fn ($value) => null !== $value));
    }

    public function coverage(string $provider, string $username): array
    {
        return $this->read($this->playerPath($provider, $username).'/coverage');
    }

    public function game(int $id): array
    {
        $this->positive($id);

        return $this->read('/v1/games/'.$id);
    }

    public function run(int $id): array
    {
        return $this->scopedResource('runs', $id);
    }

    public function job(int $id): array
    {
        return $this->scopedResource('jobs', $id);
    }

    /** options: max_games, collection_mode, batch_size, optional Unix-second since/until. */
    public function submitImport(string $provider, string $username, array $options, string $idempotencyKey): array
    {
        $this->playerPath($provider, $username);
        $this->validateOptions($options, ['max_games', 'collection_mode', 'batch_size', 'since', 'until']);
        $mode = $options['collection_mode'] ?? 'bounded';
        if (!in_array($mode, ['bounded', 'full', 'incremental', 'backfill'], true)) {
            throw new \InvalidArgumentException('Invalid collection mode.');
        }
        $this->integerOption($options, 'max_games', 1);
        $this->integerOption($options, 'batch_size', 1, 12, false);
        $this->validateWindow($options, 'bounded' === $mode);

        return $this->submit('/v1/imports', ['provider' => $provider, 'username' => $username] + $options, $idempotencyKey);
    }

    /** Explicit bounded opponent discovery; all budget fields are required. */
    public function submitCrawl(string $provider, string $username, array $options, string $idempotencyKey): array
    {
        $this->playerPath($provider, $username);
        $this->validateOptions($options, ['max_games', 'max_depth', 'max_users', 'max_jobs', 'since', 'until']);
        foreach (['max_games', 'max_users', 'max_jobs'] as $field) {
            $this->integerOption($options, $field, 1);
        }
        $this->integerOption($options, 'max_depth', 0);
        $this->validateWindow($options, true);

        return $this->submit('/v1/crawls', ['provider' => $provider, 'username' => $username] + $options, $idempotencyKey);
    }

    private function scopedResource(string $kind, int $id): array
    {
        $this->positive($id);
        $context = $this->contexts->current();
        $data = $this->request($context, 'GET', '/v1/'.$kind.'/'.$id);
        if (($data['workspace_id'] ?? null) !== $context->workspaceId || ($data['id'] ?? null) !== $id
            || !is_string($data['archive_id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $data['archive_id'])
            || !is_int($data['revision'] ?? null) || $data['revision'] < 0) {
            throw new ArchiveUnavailable(503);
        }

        return $data;
    }

    private function read(string $path, array $query = []): array
    {
        $context = $this->contexts->current();
        // Player envelopes can include private resource observations, even though games are shared.
        $this->verifyContext($context);

        return $this->request($context, 'GET', $path, ['query' => $query]);
    }

    private function submit(string $path, array $body, string $key): array
    {
        if (!preg_match('/\A[\x21-\x7e]{1,128}\z/', $key)) {
            throw new \InvalidArgumentException('Invalid idempotency key.');
        }
        $context = $this->contexts->current();
        $this->verifyContext($context);
        $result = $this->request($context, 'POST', $path, ['json' => $body, 'headers' => ['Idempotency-Key' => $key]], 202);
        if (!is_int($result['run_id'] ?? null) || $result['run_id'] < 1
            || !is_array($result['job_ids'] ?? null) || !array_is_list($result['job_ids'])
            || !is_bool($result['replayed'] ?? null)) {
            throw new ArchiveUnavailable(503);
        }
        foreach ($result['job_ids'] as $id) {
            if (!is_int($id) || $id < 1) {
                throw new ArchiveUnavailable(503);
            }
        }

        return $result;
    }

    private function verifyContext(#[\SensitiveParameter] ArchiveContext $context): void
    {
        $workspace = $this->request($context, 'GET', '/v1/workspace');
        if (($workspace['workspace_id'] ?? null) !== $context->workspaceId) {
            throw new ArchiveUnavailable(503);
        }
    }

    private function request(#[\SensitiveParameter] ArchiveContext $context, string $method, string $path, array $options = [], int $expectedStatus = 200): array
    {
        $url = parse_url($this->baseUrl);
        if (false === $url || !in_array($url['scheme'] ?? null, ['https', 'http'], true)
            || empty($url['host']) || isset($url['user'])
            || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)
            || preg_match('/\s/', $this->baseUrl)
            || ('http' === $url['scheme'] && !in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
            throw new ArchiveUnavailable(503);
        }
        try {
            $options['headers'] = ($options['headers'] ?? []) + [
                'Authorization' => $context->authorizationHeader(), 'Accept' => 'application/json',
            ];
            $options += ['timeout' => 10, 'max_duration' => 15, 'max_redirects' => 0];
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if ($expectedStatus !== $status) {
                $response->cancel();
                throw new ArchiveUnavailable(in_array($status, [404, 409, 422], true) ? $status : 503);
            }
            $content = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (strlen($content) > self::MAX_RESPONSE_BYTES) {
                    $response->cancel();
                    throw new ArchiveUnavailable(503);
                }
            }
            // Decode as object first: a JSON list is not an API response envelope.
            if (!json_decode($content, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass) {
                throw new ArchiveUnavailable(503);
            }

            return json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (ArchiveUnavailable $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ArchiveUnavailable(503);
        }
    }

    private function playerPath(string $provider, string $username): string
    {
        if (!in_array($provider, ['chess.com', 'lichess'], true)
            || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $username)) {
            throw new \InvalidArgumentException('Invalid archive player.');
        }

        return '/v1/users/'.$provider.'/'.rawurlencode($username);
    }

    private function positive(int $id): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid archive resource ID.');
        }
    }

    private function validateOptions(array $options, array $allowed): void
    {
        if (array_diff(array_keys($options), $allowed)) {
            throw new \InvalidArgumentException('Unknown collection option.');
        }
    }

    private function integerOption(array $options, string $field, int $minimum, int $maximum = PHP_INT_MAX, bool $required = true): void
    {
        if (!$required && !array_key_exists($field, $options)) {
            return;
        }
        if (!is_int($options[$field] ?? null) || $options[$field] < $minimum || $options[$field] > $maximum) {
            throw new \InvalidArgumentException('Invalid collection budget.');
        }
    }

    private function validateWindow(array $options, bool $required): void
    {
        foreach (['since', 'until'] as $field) {
            $this->integerOption($options, $field, 0, PHP_INT_MAX, $required);
        }
        if (isset($options['since'], $options['until']) && $options['since'] >= $options['until']) {
            throw new \InvalidArgumentException('Invalid collection window.');
        }
    }
}
