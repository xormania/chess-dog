<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FoundationTest extends WebTestCase
{
    public function testHomeLinksToTheFoundation(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Your chess archive, within reach.');
        self::assertSelectorExists('a[href="/foundation"]');
        self::assertSelectorExists('script[type="importmap"]');
        self::assertSelectorNotExists('meta[name="turbo-cache-control"][content="no-cache"]');
    }

    public function testFoundationRendersTheLivePreviewAndAccessibleDialog(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $client->request('GET', '/foundation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'A foundation you can inspect.');
        self::assertSelectorExists('[data-testid="archive-preview"][data-controller~="live"]');
        self::assertSelectorTextContains('[data-testid="preview-summary"]', 'Choose a player to build the preview.');
        self::assertSelectorExists('[data-testid="provider-input"] option[value="chesscom"]');
        self::assertSelectorExists('[data-testid="provider-input"] option[value="lichess"]');
        self::assertSelectorExists('[data-testid="player-input"]');
        self::assertSelectorExists('dialog[data-testid="modal-dialog"][aria-labelledby]');
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
    }
}
