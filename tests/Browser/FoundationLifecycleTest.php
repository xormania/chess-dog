<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverSelect;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class FoundationLifecycleTest extends PantherTestCase
{
    public function testLiveUpdatesAndDialogSurviveTurboNavigationAndBack(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/foundation');
        $client->waitFor('[data-controller~="flowbite-modal"][data-modal-connected="true"]');

        $this->openAndCloseDialog($client);

        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->sendKeys('hikaru');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Chess.com / hikaru');

        $provider = new WebDriverSelect($client->findElement(WebDriverBy::cssSelector('[data-testid="provider-input"]')));
        $provider->selectByValue('lichess');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Lichess / hikaru');

        $client->findElement(WebDriverBy::cssSelector('[data-testid="preview-reset"]'))->click();
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Choose a player to build the preview.');
        self::assertSame('', $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->getAttribute('value'));
        self::assertSame('chesscom', $client->findElement(WebDriverBy::cssSelector('[data-testid="provider-input"]'))->getAttribute('value'));

        $this->openAndCloseDialog($client);

        // A full document reload would discard this marker: the assertion verifies Turbo is active.
        $client->executeScript('window.__foundationDocument = "foundation-lifecycle";');
        $client->getCrawler()->filter('a[href="/"]')->first()->click();
        $client->waitForElementToContain('h1', 'Your chess archive, within reach.');
        self::assertSame('foundation-lifecycle', $client->executeScript('return window.__foundationDocument;'));

        $client->back();
        $client->waitForElementToContain('h1', 'A foundation you can inspect.');
        $client->waitFor('[data-controller~="flowbite-modal"][data-modal-connected="true"]');
        self::assertSame('foundation-lifecycle', $client->executeScript('return window.__foundationDocument;'));

        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->sendKeys(WebDriverKeys::ENTER);
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        $client->getKeyboard()->pressKey(WebDriverKeys::ESCAPE);
        $client->waitForInvisibility('[data-testid="modal-dialog"]');
        self::assertSame('modal-open', $client->executeScript('return document.activeElement.dataset.testid;'));

        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->clear();
        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->sendKeys('magnus');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Chess.com / magnus');
    }

    private function openAndCloseDialog(Client $client): void
    {
        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->click();
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        self::assertSelectorIsVisible('[data-testid="modal-close"]');

        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-close"]'))->click();
        $client->waitForInvisibility('[data-testid="modal-dialog"]');
        self::assertSame('modal-open', $client->executeScript('return document.activeElement.dataset.testid;'));
    }
}
