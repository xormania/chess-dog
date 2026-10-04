<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Archive\CrawlClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlayerArchiveTest extends WebTestCase
{
    public function testSearchDoesNotContactTheArchive(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $client->request('GET', '/players');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Find a player.');
        $client->request('GET', '/players?provider=lichess&username=Player');
        self::assertResponseRedirects('/players/lichess/Player');
    }

    public function testPlayerReadUsesOnlyArchiveGetsAndEscapesProfileData(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            return new MockResponse(json_encode(match (true) {
                str_ends_with($url, '/games?limit=50') => ['items' => [['id' => 1, 'white_username' => 'Player', 'black_username' => 'Opponent', 'outcome' => 'white_win', 'time_control_raw' => '300+3']], 'next_cursor' => 1],
                str_ends_with($url, '/coverage') => [],
                default => ['display_username' => 'Player', 'profile' => ['real_name' => '<script>alert(1)</script>']],
            }, JSON_THROW_ON_ERROR));
        });
        self::getContainer()->set(CrawlClient::class, new CrawlClient($http, 'https://archive.example', 'secret-never-in-html'));
        $client->request('GET', '/players/lichess/Player');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Player');
        self::assertSelectorTextContains('table', 'Opponent');
        self::assertSelectorExists('a[href="/players/lichess/Player?after=1"]');
        self::assertStringNotContainsString('<script>alert(1)</script>', $client->getResponse()->getContent());
        self::assertStringNotContainsString('secret-never-in-html', $client->getResponse()->getContent());
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testCollectionRequiresCsrfAndDoesNotSubmitOnInvalidToken(): void
    {
        $client = self::createClient();
        $http = new MockHttpClient();
        self::getContainer()->set(CrawlClient::class, new CrawlClient($http, 'https://archive.example', 'token'));
        $client->request('POST', '/players/lichess/Player/collect', ['_token' => 'invalid', 'since' => '2026-01-01', 'until' => '2026-02-01', 'submission_key' => str_repeat('a', 32)]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testClockEvidenceDistinguishesZeroUnknownAndExactDecimals(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $clock = ['node_index' => 1, 'kind' => 'remaining', 'seconds' => '0.000', 'source' => 'pgn', 'raw_text' => '0:00:00.000', 'precision_seconds' => '0.001', 'status' => 'parsed'];
        $http = new MockHttpClient(new MockResponse(json_encode([
            'id' => 3, 'parse_status' => 'complete', 'nodes' => [], 'sources' => [],
            'clocks' => [$clock, array_replace($clock, ['node_index' => null, 'seconds' => null, 'status' => 'unmapped', 'source' => 'provider'])],
        ], JSON_THROW_ON_ERROR)));
        self::getContainer()->set(CrawlClient::class, new CrawlClient($http, 'https://archive.example', 'token'));
        $client->request('GET', '/games/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section[aria-labelledby="clocks-heading"] table', '0.000');
        self::assertSelectorTextContains('section[aria-labelledby="clocks-heading"] table', '0.001 s');
        self::assertSelectorTextContains('section[aria-labelledby="clocks-heading"] table', 'Unknown');
        self::assertSelectorTextContains('section[aria-labelledby="clocks-heading"] table', 'Unmapped');
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testValidCollectionFormSubmitsOnceAndRedirectsToProgress(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $client->disableReboot();
        $posts = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$posts): MockResponse {
            if ('GET' === $method) {
                return new MockResponse('{}', ['http_code' => 404]);
            }
            ++$posts;
            self::assertSame('https://archive.example/v1/imports', $url);
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1767225600, $body['since']);
            self::assertSame(1769904000, $body['until']);

            return new MockResponse('{"run_id":15,"job_ids":[9],"replayed":false}', ['http_code' => 202]);
        });
        self::getContainer()->set(CrawlClient::class, new CrawlClient($http, 'https://archive.example', 'token'));
        $crawler = $client->request('GET', '/players/lichess/Player');
        $form = $crawler->selectButton('Collect games')->form(['since' => '2026-01-01', 'until' => '2026-02-01']);
        $client->submit($form);
        self::assertResponseRedirects('/collections/15', 303);
        self::assertSame(1, $posts);
    }
}
