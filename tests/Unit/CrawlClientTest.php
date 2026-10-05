<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Archive\ArchiveUnavailable;
use App\Archive\ConfiguredArchiveContextProvider;
use App\Archive\CrawlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CrawlClientTest extends TestCase
{
    public function testUsesFixedServerCredentialsAndScopedResourcePath(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertSame('GET', $method);
            self::assertSame('https://crawl.example/v1/runs/7', $url);
            self::assertSame(['Authorization: Bearer server-test-token'], $options['normalized_headers']['authorization']);
            self::assertArrayNotHasKey('x-workspace-id', $options['normalized_headers']);
            self::assertSame(0, $options['max_redirects']);

            return $this->response($this->snapshot());
        });

        self::assertSame('local', $this->client($http)->run(7)['workspace_id']);
    }

    public function testRejectsOtherWorkspaceSnapshot(): void
    {
        $http = new MockHttpClient($this->response(array_replace($this->snapshot(), ['workspace_id' => 'other'])));
        $this->expectException(ArchiveUnavailable::class);
        $this->client($http)->run(7);
    }

    public function testFullHistorySubmissionKeepsDatesOmittedAndVerifiesAuthorityFirst(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls) {
            ++$calls;
            if (1 === $calls) {
                self::assertSame('GET', $method);
                self::assertStringEndsWith('/v1/workspace', $url);

                return $this->response(['workspace_id' => 'local']);
            }
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/v1/imports', $url);
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['provider' => 'lichess', 'username' => 'Player', 'max_games' => 500, 'collection_mode' => 'full', 'batch_size' => 2], $body);
            self::assertArrayNotHasKey('since', $body);
            self::assertSame(['Idempotency-Key: import-test-1'], $options['normalized_headers']['idempotency-key']);

            return $this->response(['run_id' => 7, 'job_ids' => [8], 'replayed' => false], 202);
        });
        self::assertSame(7, $this->client($http)->submitImport('lichess', 'Player', [
            'max_games' => 500, 'collection_mode' => 'full', 'batch_size' => 2,
        ], 'import-test-1')['run_id']);
        self::assertSame(2, $calls);
    }

    public function testWrongCredentialBindingCannotSubmit(): void
    {
        $http = new MockHttpClient([$this->response(['workspace_id' => 'other'])]);
        $this->expectException(ArchiveUnavailable::class);
        $this->client($http)->submitImport('lichess', 'Player', ['max_games' => 500, 'collection_mode' => 'incremental'], 'import-test');
    }

    public function testWrongCredentialBindingCannotReadPrivatePlayerObservations(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url) use (&$calls) {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/v1/workspace', $url);

            return $this->response(['workspace_id' => 'alpha']);
        });
        $client = new CrawlClient($http, new ConfiguredArchiveContextProvider('beta', 'alpha-server-token'), 'https://crawl.example');
        try {
            $client->player('lichess', 'Player');
            self::fail('Mismatched credential could read private observations.');
        } catch (ArchiveUnavailable $error) {
            self::assertSame(503, $error->statusCode);
            self::assertSame(1, $calls); // No request for the rich player body occurred.
        }
    }

    public function testPlayerReadVerifiesBindingThenReturnsStoredObservations(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls) {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertSame(['Authorization: Bearer server-test-token'], $options['normalized_headers']['authorization']);
            if (1 === $calls) {
                self::assertStringEndsWith('/v1/workspace', $url);

                return $this->response(['workspace_id' => 'local']);
            }
            self::assertStringEndsWith('/v1/users/lichess/Player', $url);

            return $this->response(['resources' => [['owner_scope' => 'local']]]);
        });
        self::assertSame('local', $this->client($http)->player('lichess', 'Player')['resources'][0]['owner_scope']);
        self::assertSame(2, $calls);
    }

    public function testCallerCannotChooseWorkspaceOrInjectPaths(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('Network should not be called.'));
        foreach (['../secret', 'Player?token=x', 'Player/name'] as $username) {
            try {
                $this->client($http)->player('lichess', $username);
                self::fail('Invalid username accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->client($http)->submitImport('lichess', 'Player', ['max_games' => 500, 'workspace_id' => 'other'], 'import-test');
    }

    public function testRemoteFailureDoesNotLeakCredentialsOrResponse(): void
    {
        $http = new MockHttpClient(new MockResponse('upstream-secret server-test-token', ['http_code' => 500]));
        try {
            $this->client($http)->run(7);
            self::fail('Failure accepted.');
        } catch (ArchiveUnavailable $error) {
            self::assertSame(503, $error->statusCode);
            self::assertSame('Archive request unavailable.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('server-test-token', (string) $error);
        }
    }

    public function testMalformedAndOversizedPayloadsAreRejected(): void
    {
        foreach (['[]', 'null', '{broken', '{"data":"'.str_repeat('x', 4 * 1024 * 1024).'"}'] as $body) {
            try {
                $this->client(new MockHttpClient([$this->response(['workspace_id' => 'local']), new MockResponse($body)]))->player('lichess', 'Player');
                self::fail('Invalid payload accepted.');
            } catch (ArchiveUnavailable $error) {
                self::assertSame(503, $error->statusCode);
            }
        }
    }

    public function testMissingCredentialsAndUnsafeBaseUrlDoNotMakeRequests(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('Network should not be called.'));
        foreach (['https://token@crawl.example', 'https://crawl.example/path', 'http://remote.example'] as $url) {
            try {
                $this->client($http, $url)->player('lichess', 'Player');
                self::fail('Unsafe API base accepted.');
            } catch (ArchiveUnavailable) {
            }
        }
        $this->expectException(ArchiveUnavailable::class);
        (new CrawlClient($http, new ConfiguredArchiveContextProvider('local', ''), 'https://crawl.example'))->player('lichess', 'Player');
    }

    public function testBoundedAndDiscoveryRequestsRequireExplicitBudgetsAndWindows(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('Network should not be called.'));
        try {
            $this->client($http)->submitImport('lichess', 'Player', ['max_games' => 5], 'import-test');
            self::fail('Unbounded default accepted.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->client($http)->submitCrawl('lichess', 'Player', ['max_games' => 5, 'since' => 100, 'until' => 200], 'crawl-test');
    }

    public function testContextJsonAndDebugOutputExcludeServerToken(): void
    {
        $provider = new ConfiguredArchiveContextProvider('local', 'server-test-token');
        self::assertSame('{"workspaceId":"local"}', json_encode($provider->current(), JSON_THROW_ON_ERROR));
        ob_start();
        var_dump($provider, $provider->current());
        $debug = ob_get_clean();
        self::assertStringNotContainsString('server-test-token', $debug);
    }

    public function testRedirectCannotForwardBearerCredential(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://other.example/']]);
        });
        try {
            $this->client($http)->player('lichess', 'Player');
            self::fail('Redirect followed.');
        } catch (ArchiveUnavailable $error) {
            self::assertSame(503, $error->statusCode);
            self::assertSame(1, $calls);
        }
    }

    private function client(MockHttpClient $http, string $base = 'https://crawl.example'): CrawlClient
    {
        return new CrawlClient($http, new ConfiguredArchiveContextProvider('local', 'server-test-token'), $base);
    }

    private function response(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    private function snapshot(): array
    {
        return ['id' => 7, 'archive_id' => str_repeat('a', 32), 'workspace_id' => 'local', 'revision' => 3];
    }
}
