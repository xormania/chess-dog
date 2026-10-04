<?php

declare(strict_types=1);

namespace App\Archive;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Server-side archive access. Credentials and provider requests stay out of the browser. */
final class CrawlClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $token,
    ) {
    }

    public function player(string $provider, string $username): array
    {
        return $this->request('GET', $this->playerPath($provider, $username));
    }

    public function games(string $provider, string $username, ?int $after = null): array
    {
        return $this->request('GET', $this->playerPath($provider, $username).'/games', [
            'query' => array_filter(['after' => $after, 'limit' => 50], static fn ($value) => null !== $value),
        ]);
    }

    public function coverage(string $provider, string $username): array
    {
        return $this->request('GET', $this->playerPath($provider, $username).'/coverage');
    }

    public function game(int $id): array
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Choose a valid game.');
        }

        return $this->request('GET', '/v1/games/'.$id);
    }

    public function run(int $id): array
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Choose a valid collection.');
        }

        return $this->request('GET', '/v1/runs/'.$id);
    }

    public function collect(string $provider, string $username, int $since, int $until, string $key): array
    {
        $this->playerPath($provider, $username);
        if ($since < 0 || $until <= $since || $until - $since > 366 * 86400
            || !preg_match('/\A[a-f0-9]{32}\z/D', $key)) {
            throw new \InvalidArgumentException('Choose a date range of up to one year.');
        }

        return $this->request('POST', '/v1/imports', [
            'headers' => ['Idempotency-Key' => $key],
            'json' => ['provider' => $provider, 'username' => $username, 'since' => $since, 'until' => $until, 'max_games' => 1000],
        ]);
    }

    private function playerPath(string $provider, string $username): string
    {
        if (!in_array($provider, ['chess.com', 'lichess'], true)
            || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $username)) {
            throw new \InvalidArgumentException('Choose a provider and enter a valid player name.');
        }

        return '/v1/users/'.rawurlencode($provider).'/'.rawurlencode($username);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $url = parse_url($this->baseUrl);
        if ('' === $this->token || false === $url || !isset($url['scheme'], $url['host'])
            || !in_array($url['scheme'], ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || (isset($url['path']) && !in_array($url['path'], ['', '/'], true))) {
            throw new ArchiveUnavailable();
        }

        try {
            $options['headers']['Authorization'] = 'Bearer '.$this->token;
            $options['headers']['Accept'] = 'application/json';
            $options['timeout'] = 10;
            $options['max_duration'] = 15;
            $options['max_redirects'] = 0;
            $response = $this->http->request($method, rtrim($this->baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new ArchiveUnavailable(in_array($status, [404, 409, 422], true) ? $status : 503);
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > 4 * 1024 * 1024) {
                    $response->cancel();
                    throw new ArchiveUnavailable();
                }
            }
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new ArchiveUnavailable();
            }

            return $data;
        } catch (ArchiveUnavailable $error) {
            throw $error;
        } catch (\Throwable) {
            // Remote errors can contain credentials, URLs or provider data.
            throw new ArchiveUnavailable();
        }
    }
}
