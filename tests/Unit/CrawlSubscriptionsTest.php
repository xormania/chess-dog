<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Archive\ArchiveUnavailable;
use App\Archive\ConfiguredArchiveContextProvider;
use App\Archive\CrawlClient;
use App\Archive\CrawlSubscriptions;
use App\Archive\TopicNames;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;

final class CrawlSubscriptionsTest extends TestCase
{
    private const SECRET = 'subscriber-test-secret-32-characters-minimum';

    public function testSignedGrantIsPrivateExactResourceOnlyAndCookieIsNotBrowserConfiguration(): void
    {
        $subscription = $this->service()->forRun(Request::create('https://dog.example/archive'), 7);
        $cookie = $subscription->cookie();
        self::assertSame(['https://chess-crawl.local/workspaces/local/runs/7'], $subscription->topics);
        self::assertNull($cookie->getDomain());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertEqualsWithDelta(time() + 300, $cookie->getExpiresTime(), 3);
        [$header, $body, $signature] = explode('.', $cookie->getValue());
        $claims = json_decode($this->decode($body), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($subscription->topics, $claims['mercure']['subscribe']);
        self::assertArrayNotHasKey('publish', $claims['mercure']);
        self::assertSame(hash_hmac('sha256', $header.'.'.$body, self::SECRET, true), $this->decode($signature));
        $visible = json_encode($subscription->connectionOptions(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $visible);
        self::assertStringNotContainsString('server-test-token', $visible);
        self::assertStringNotContainsString($cookie->getValue(), $visible);
        self::assertStringNotContainsString($cookie->getValue(), json_encode($subscription, JSON_THROW_ON_ERROR));
        ob_start();
        var_dump($subscription, $this->service());
        $debug = ob_get_clean();
        self::assertStringNotContainsString(self::SECRET, $debug);
        self::assertStringNotContainsString($cookie->getValue(), $debug);
    }

    public function testJobOwnershipMustBeReadBeforeGrantingItsTopic(): void
    {
        $http = new MockHttpClient(function (string $method, string $url) {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/v1/jobs/9', $url);

            return new MockResponse(json_encode($this->snapshot(9), JSON_THROW_ON_ERROR));
        });
        self::assertSame(['https://chess-crawl.local/workspaces/local/jobs/9'], $this->service($http)->forJob(
            Request::create('https://dog.example/'), 9,
        )->topics);
    }

    public function testOtherWorkspaceCannotReceiveSubscriberGrant(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(array_replace($this->snapshot(), ['workspace_id' => 'other']), JSON_THROW_ON_ERROR)));
        $this->expectException(ArchiveUnavailable::class);
        $this->service($http)->forRun(Request::create('https://dog.example/'), 7);
    }

    public function testMissingResourceCannotReceiveSubscriberGrant(): void
    {
        $this->expectException(ArchiveUnavailable::class);
        $this->service(new MockHttpClient(new MockResponse('not found', ['http_code' => 404])))->forRun(
            Request::create('https://dog.example/'), 7,
        );
    }

    public function testRequiresSameOriginHubAndConfiguredSigningSecret(): void
    {
        foreach ([['https://other.example/.well-known/mercure', self::SECRET], ['https://dog.example/.well-known/mercure', '']] as [$url, $secret]) {
            try {
                $this->service(publicUrl: $url, secret: $secret)->forRun(Request::create('https://dog.example/'), 7);
                self::fail('Unsafe subscriber configuration accepted.');
            } catch (ArchiveUnavailable $error) {
                self::assertSame(503, $error->statusCode);
            }
        }
    }

    public function testDefaultPortNotationDoesNotChangeOrigin(): void
    {
        foreach ([
            ['https://dog.example:443/.well-known/mercure', 'https://dog.example/'],
            ['https://dog.example/.well-known/mercure', 'https://dog.example:443/'],
            ['http://dog.example:80/.well-known/mercure', 'http://dog.example/'],
            ['https://dog.example:8443/.well-known/mercure', 'https://dog.example:8443/'],
        ] as [$publicUrl, $requestUrl]) {
            self::assertSame(['https://chess-crawl.local/workspaces/local/runs/7'], $this->service(
                publicUrl: $publicUrl,
            )->forRun(Request::create($requestUrl), 7)->topics);
        }
    }

    private function service(?MockHttpClient $http = null, string $publicUrl = 'https://dog.example/.well-known/mercure', string $secret = self::SECRET): CrawlSubscriptions
    {
        $contexts = new ConfiguredArchiveContextProvider('local', 'server-test-token');
        $http ??= new MockHttpClient(new MockResponse(json_encode($this->snapshot(), JSON_THROW_ON_ERROR)));
        $hub = new Hub('http://127.0.0.1:3000/.well-known/mercure', new StaticTokenProvider('unused'), new LcobucciFactory($secret), $publicUrl);

        return new CrawlSubscriptions(new CrawlClient($http, $contexts, 'https://crawl.example'), $contexts,
            new TopicNames('https://chess-crawl.local'), new HubRegistry($hub, ['crawl' => $hub]), $secret);
    }

    private function snapshot(int $id = 7): array
    {
        return ['id' => $id, 'archive_id' => str_repeat('a', 32), 'workspace_id' => 'local', 'revision' => 3, 'job_ids' => range(1, 1000)];
    }

    private function decode(string $base64): string
    {
        return base64_decode(strtr($base64, '-_', '+/'), true);
    }
}
