<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Remote\DriverCommand;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverSelect;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class FoundationLifecycleTest extends PantherTestCase
{
    public function testNativeNavigationBackStartsAConsistentFreshPreview(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/foundation');
        $client->waitFor('[data-controller~="flowbite-modal"][data-modal-connected="true"]');

        $provider = new WebDriverSelect($client->findElement(WebDriverBy::cssSelector('[data-testid="provider-input"]')));
        $provider->selectByValue('lichess');
        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->sendKeys('previous-player');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Lichess / previous-player');
        $client->executeScript('window.__nativeHistoryDocument = "before-navigation";');

        // WebDriver navigation loads a document, bypassing Turbo's link handling.
        $homeUrl = $client->executeScript('return document.querySelector("nav[aria-label=\"Main navigation\"] a[href=\"/\"]").href;');
        $client->getWebDriver()->get($homeUrl);
        $client->waitForElementToContain('h1', 'Your chess archive, within reach.');
        self::assertNull($client->executeScript('return window.__nativeHistoryDocument ?? null;'));

        $client->back();
        $client->waitFor('[data-controller~="flowbite-modal"][data-modal-connected="true"]');
        self::assertSame('', $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->getAttribute('value'));
        self::assertSame('chesscom', $client->findElement(WebDriverBy::cssSelector('[data-testid="provider-input"]'))->getAttribute('value'));
        self::assertSelectorTextContains('[data-testid="preview-summary"]', 'Choose a player to build the preview.');

        // The restored select and the signed Live state must agree on the next edit.
        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->sendKeys('next-player');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Chess.com / next-player');
        self::assertSame('chesscom', $client->findElement(WebDriverBy::cssSelector('[data-testid="provider-input"]'))->getAttribute('value'));
    }

    public function testNativeDialogCancellationAndCloseSynchronizeAccessibleState(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/foundation');
        $client->waitFor('[data-controller~="flowbite-modal"][data-modal-connected="true"]');
        $client->executeScript(<<<'JS'
            window.__nativeDialogCancellations = [];
            document.querySelector('[data-testid="modal-dialog"]').addEventListener('cancel', event => {
                window.__nativeDialogCancellations.push(event.isTrusted);
            });
            JS);

        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->click();
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        self::assertSame('false', $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-dialog"]'))->getAttribute('aria-hidden'));
        self::assertSame('true', $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->getAttribute('aria-expanded'));
        $client->getKeyboard()->pressKey(WebDriverKeys::TAB);
        $client->getKeyboard()->pressKey(WebDriverKeys::ESCAPE);
        $client->waitForInvisibility('[data-testid="modal-dialog"]');
        self::assertSame([true], $client->executeScript('return window.__nativeDialogCancellations;'), 'Escape must exercise the trusted native cancel event.');
        $this->assertClosedDialogState($client);
        self::assertSame('modal-open', $client->executeScript('return document.activeElement.dataset.testid;'));

        // Closing through the native API must synchronize after the close event.
        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->click();
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        $client->executeScript('document.querySelector("[data-testid=modal-dialog]").close();');
        $client->waitForAttributeToContain('[data-testid="modal-dialog"]', 'aria-hidden', 'true');
        $this->assertClosedDialogState($client);

        // A queued close event must not mark an immediately reopened dialog closed.
        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->click();
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        $client->executeScript(<<<'JS'
            window.__nativeDialogCloseObserved = false;
            const dialog = document.querySelector('[data-testid="modal-dialog"]');
            dialog.addEventListener('close', () => { window.__nativeDialogCloseObserved = true; }, { once: true });
            dialog.close();
            dialog.showModal();
            JS);
        $client->wait()->until(fn () => $client->executeScript('return window.__nativeDialogCloseObserved;'));
        self::assertTrue($client->executeScript('return document.querySelector("[data-testid=modal-dialog]").open;'));
        self::assertSame('false', $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-dialog"]'))->getAttribute('aria-hidden'));
        self::assertSame('true', $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->getAttribute('aria-expanded'));
        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-close"]'))->click();
        $client->waitForInvisibility('[data-testid="modal-dialog"]');
        $this->assertClosedDialogState($client);
    }

    public function testLiveUpdatesAndDialogSurviveTurboNavigationAndBack(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/foundation');
        $client->waitFor('[data-controller~="flowbite-modal"][data-modal-connected="true"]');

        $this->openAndCloseDialog($client);
        $this->assertDialogPaddingAndBackdropClicks($client);

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

        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->sendKeys('completed-player');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Chess.com / completed-player');
        $provider = new WebDriverSelect($client->findElement(WebDriverBy::cssSelector('[data-testid="provider-input"]')));
        $provider->selectByValue('lichess');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Lichess / completed-player');

        // Leave while the final edit is still debounced: returning must start a fresh preview.
        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->clear();
        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->sendKeys('pending-player');
        $client->getCrawler()->filter('a[href="/"]')->first()->click();
        $client->waitForElementToContain('h1', 'Your chess archive, within reach.');
        self::assertSame('foundation-lifecycle', $client->executeScript('return window.__foundationDocument;'));

        $client->back();
        $client->waitForElementToContain('h1', 'A foundation you can inspect.');
        $client->waitFor('[data-controller~="flowbite-modal"][data-modal-connected="true"]');
        self::assertSame('foundation-lifecycle', $client->executeScript('return window.__foundationDocument;'));
        self::assertSame('', $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->getAttribute('value'));
        self::assertSame('chesscom', $client->findElement(WebDriverBy::cssSelector('[data-testid="provider-input"]'))->getAttribute('value'));
        self::assertSelectorTextContains('[data-testid="preview-summary"]', 'Choose a player to build the preview.');

        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->sendKeys(WebDriverKeys::ENTER);
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        $client->getKeyboard()->pressKey(WebDriverKeys::ESCAPE);
        $client->waitForInvisibility('[data-testid="modal-dialog"]');
        self::assertSame('modal-open', $client->executeScript('return document.activeElement.dataset.testid;'));

        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->clear();
        $client->findElement(WebDriverBy::cssSelector('[data-testid="player-input"]'))->sendKeys('magnus');
        $client->waitForElementToContain('[data-testid="preview-summary"]', 'Chess.com / magnus');

        $client->manage()->window()->setSize(new WebDriverDimension(320, 900));
        // WebDriver can return from resize before Chrome paints the responsive layout.
        $client->getWebDriver()->executeAsyncScript('const done = arguments[arguments.length - 1]; requestAnimationFrame(() => requestAnimationFrame(done));');
        $this->assertSmallScreenHeader($client);
        $this->assertSmallScreenDialogTitle($client);
        $client->getCrawler()->filter('nav[aria-label="Main navigation"] a[href="/"]')->click();
        $client->waitForElementToContain('h1', 'Your chess archive, within reach.');
        $this->assertSmallScreenHeader($client);
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

    private function assertClosedDialogState(Client $client): void
    {
        self::assertSame('true', $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-dialog"]'))->getAttribute('aria-hidden'));
        self::assertSame('false', $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->getAttribute('aria-expanded'));
    }

    private function assertDialogPaddingAndBackdropClicks(Client $client): void
    {
        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->click();
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        $bounds = $client->executeScript('return document.querySelector("[data-testid=modal-dialog]").getBoundingClientRect().toJSON();');

        $this->clickAt($client, (int) floor($bounds['left'] + 8), (int) floor($bounds['top'] + 8));
        self::assertTrue(
            $client->executeScript('return document.querySelector("[data-testid=modal-dialog]").open;'),
            'A pointer click on padding inside the dialog must leave it open.',
        );

        $outsideX = (int) floor($bounds['left'] - 8);
        self::assertGreaterThanOrEqual(0, $outsideX);
        $this->clickAt($client, $outsideX, (int) floor($bounds['top'] + 8));
        $client->waitForInvisibility('[data-testid="modal-dialog"]');
        self::assertSame('modal-open', $client->executeScript('return document.activeElement.dataset.testid;'));
    }

    private function clickAt(Client $client, int $x, int $y): void
    {
        // Real W3C pointer input exercises the native dialog's backdrop retargeting.
        $client->execute(DriverCommand::ACTIONS, [
            'actions' => [[
                'type' => 'pointer',
                'id' => 'mouse',
                'parameters' => ['pointerType' => 'mouse'],
                'actions' => [
                    ['type' => 'pointerMove', 'duration' => 0, 'origin' => 'viewport', 'x' => $x, 'y' => $y],
                    ['type' => 'pointerDown', 'button' => 0],
                    ['type' => 'pointerUp', 'button' => 0],
                ],
            ]],
        ]);
    }

    private function assertSmallScreenHeader(Client $client): void
    {
        [$viewportWidth, $layoutWidth] = $client->executeScript('return [window.innerWidth, document.documentElement.clientWidth];');
        self::assertSame(320, $viewportWidth);
        self::assertLessThanOrEqual(
            $layoutWidth,
            $client->executeScript('return document.documentElement.scrollWidth;'),
            'A 320 px screen must not have horizontal page overflow.',
        );

        foreach (['/' => 'Home', '/foundation' => 'Foundation'] as $href => $label) {
            $selector = 'nav[aria-label="Main navigation"] a[href="'.$href.'"]';
            self::assertSelectorIsVisible($selector);
            $link = $client->findElement(WebDriverBy::cssSelector($selector));
            self::assertSame($label, $link->getText());
            [$left, $right, $fontSize] = $client->executeScript(
                'const rect = arguments[0].getBoundingClientRect(); return [rect.left, rect.right, parseFloat(getComputedStyle(arguments[0]).fontSize)];',
                [$link],
            );
            self::assertGreaterThanOrEqual(0, $left);
            self::assertLessThanOrEqual($layoutWidth, $right);
            self::assertGreaterThanOrEqual(12, $fontSize, 'Navigation labels must retain a readable font size.');
        }
    }

    private function assertSmallScreenDialogTitle(Client $client): void
    {
        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-open"]'))->click();
        $client->waitForVisibility('[data-testid="modal-dialog"]');
        $titleId = $client->executeScript('return document.querySelector("[data-testid=modal-dialog]").getAttribute("aria-labelledby");');
        self::assertTrue($client->findElement(WebDriverBy::id($titleId))->isDisplayed(), 'The dialog title referenced by aria-labelledby must be visible.');
        self::assertFalse($client->executeScript(<<<'JS'
            const dialog = document.querySelector('[data-testid="modal-dialog"]');
            const title = document.getElementById(dialog.getAttribute('aria-labelledby'));
            const text = document.createRange();
            text.selectNodeContents(title);
            const close = document.querySelector('[data-testid="modal-close"]').getBoundingClientRect();

            return [...text.getClientRects()].some(rect =>
                rect.left < close.right && rect.right > close.left
                && rect.top < close.bottom && rect.bottom > close.top
            );
            JS), 'At 320 px, no title text may overlap the close control.');

        $client->findElement(WebDriverBy::cssSelector('[data-testid="modal-close"]'))->click();
        $client->waitForInvisibility('[data-testid="modal-dialog"]');
        self::assertSame('modal-open', $client->executeScript('return document.activeElement.dataset.testid;'));
    }
}
