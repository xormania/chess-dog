<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ArchivePreview
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $provider = 'chesscom';

    #[LiveProp(writable: true)]
    public string $player = '';

    public function getSummary(): string
    {
        $provider = match ($this->provider) {
            'chesscom' => 'Chess.com',
            'lichess' => 'Lichess',
            default => null,
        };

        if (null === $provider) {
            return 'Choose a provider for the preview.';
        }

        $player = trim($this->player);

        if ('' === $player) {
            return 'Choose a player to build the preview.';
        }

        return $provider.' / '.$player;
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->provider = 'chesscom';
        $this->player = '';
    }
}
