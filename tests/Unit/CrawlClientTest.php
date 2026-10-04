<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Archive\ArchiveUnavailable;
use App\Archive\CrawlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CrawlClientTest extends TestCase
{
    public function testLookupUsesServerCredentialsAndNeverFollowsRedirects(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://archive.example/v1/users/chess.com/Player_1', $url);
            self::assertContains('Authorization: Bearer private-test-token', $options['headers']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse('{"display_username":"Player_1"}');
        });
        $crawl = new CrawlClient($http, 'https://archive.example', 'private-test-token');
        self::assertSame('Player_1', $crawl->player('chess.com', 'Player_1')['display_username']);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testPathTraversalIsRejectedBeforeARequest(): void
    {
        $http = new MockHttpClient();
        $crawl = new CrawlClient($http, 'https://archive.example', 'token');
        try {
            $crawl->player('lichess', '../admin');
            self::fail('Invalid player accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    public function testRemoteErrorsDoNotExposeTheResponse(): void
    {
        $http = new MockHttpClient(new MockResponse('secret-token upstream stack trace', ['http_code' => 500]));
        $crawl = new CrawlClient($http, 'https://archive.example', 'token');
        try {
            $crawl->player('lichess', 'Player');
            self::fail('Failed response accepted');
        } catch (ArchiveUnavailable $error) {
            self::assertSame(503, $error->status);
            self::assertSame('Your archive is temporarily unavailable.', $error->getMessage());
        }
    }

    public function testMissingTokenDoesNotContactBackend(): void
    {
        $http = new MockHttpClient();
        $crawl = new CrawlClient($http, 'https://archive.example', '');
        try {
            $crawl->player('lichess', 'Player');
            self::fail('Unauthenticated configuration accepted');
        } catch (ArchiveUnavailable) {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    public function testExplicitCollectionKeepsIdempotencyKeyAndDateBounds(): void
    {
        $key = str_repeat('a', 32);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($key): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://archive.example/v1/imports', $url);
            self::assertContains('Idempotency-Key: '.$key, $options['headers']);
            self::assertSame(['provider' => 'lichess', 'username' => 'Player', 'since' => 1700000000, 'until' => 1700100000, 'max_games' => 1000], json_decode($options['body'], true));

            return new MockResponse('{"run_id":12,"job_ids":[8],"replayed":false}', ['http_code' => 202]);
        });
        $crawl = new CrawlClient($http, 'https://archive.example', 'token');
        self::assertSame(12, $crawl->collect('lichess', 'Player', 1700000000, 1700100000, $key)['run_id']);
    }

    public function testOversizedResponseIsCancelled(): void
    {
        $response = new MockResponse(str_repeat('x', 4 * 1024 * 1024 + 1));
        $crawl = new CrawlClient(new MockHttpClient($response), 'https://archive.example', 'token');
        $this->expectException(ArchiveUnavailable::class);
        $crawl->player('lichess', 'Player');
    }
}
