<?php

declare(strict_types=1);

namespace App\Controller;

use App\Archive\ArchiveUnavailable;
use App\Archive\CrawlClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PlayerController extends AbstractController
{
    #[Route('/players', name: 'app_players', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $provider = $request->query->getString('provider', 'chess.com');
        $username = trim($request->query->getString('username'));
        if ('' !== $username && in_array($provider, ['chess.com', 'lichess'], true)
            && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $username)) {
            return $this->redirectToRoute('app_player', ['provider' => $provider, 'username' => $username]);
        }

        return $this->render('archive/search.html.twig', [
            'provider' => $provider, 'username' => $username,
            'error' => '' !== $username ? 'Enter a valid player name.' : null,
        ]);
    }

    #[Route('/players/{provider}/{username}', name: 'app_player', requirements: ['provider' => 'chess\.com|lichess', 'username' => '[A-Za-z0-9_-]{1,100}'], methods: ['GET'])]
    public function player(string $provider, string $username, Request $request, CrawlClient $crawl): Response
    {
        $after = $request->query->get('after');
        if (null !== $after && (!is_string($after) || !ctype_digit($after) || strlen($after) > 18)) {
            throw $this->createNotFoundException();
        }
        $profile = null;
        $games = ['items' => [], 'next_cursor' => null];
        $coverage = [];
        $error = null;
        try {
            $profile = $crawl->player($provider, $username);
            $games = $crawl->games($provider, $username, null === $after ? null : (int) $after);
            $coverage = $crawl->coverage($provider, $username);
        } catch (ArchiveUnavailable $exception) {
            $error = $exception->getMessage();
        }

        return $this->render('archive/player.html.twig', [
            'provider' => $provider, 'username' => $username, 'profile' => $profile,
            'games' => $games, 'coverage' => $coverage, 'error' => $error,
            'submission_key' => bin2hex(random_bytes(16)),
            'since' => (new \DateTimeImmutable('-1 month', new \DateTimeZone('UTC')))->format('Y-m-d'),
            'until' => (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('Y-m-d'),
        ]);
    }

    #[Route('/players/{provider}/{username}/collect', name: 'app_player_collect', requirements: ['provider' => 'chess\.com|lichess', 'username' => '[A-Za-z0-9_-]{1,100}'], methods: ['POST'])]
    public function collect(string $provider, string $username, Request $request, CrawlClient $crawl): Response
    {
        if (!$this->isCsrfTokenValid('collect:'.$provider.':'.$username, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Reload this page and try again.');
        }
        try {
            $since = $this->date($request->request->getString('since'));
            $until = $this->date($request->request->getString('until'));
            $result = $crawl->collect($provider, $username, $since, $until, $request->request->getString('submission_key'));
            if (!isset($result['run_id']) || !is_int($result['run_id']) || $result['run_id'] < 1) {
                throw new ArchiveUnavailable();
            }

            return $this->redirectToRoute('app_archive_run', ['id' => $result['run_id']], Response::HTTP_SEE_OTHER);
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('archive_error', $error->getMessage());
        } catch (ArchiveUnavailable) {
            $this->addFlash('archive_error', 'Collection could not be submitted. Check your archive connection and try again.');
        }

        return $this->redirectToRoute('app_player', ['provider' => $provider, 'username' => $username], Response::HTTP_SEE_OTHER);
    }

    #[Route('/collections/{id}', name: 'app_archive_run', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['GET'])]
    public function run(int $id, CrawlClient $crawl): Response
    {
        try {
            $run = $crawl->run($id);
        } catch (ArchiveUnavailable $error) {
            return $this->render('archive/run.html.twig', ['run' => null, 'error' => $error->getMessage()], new Response(status: $error->status));
        }

        return $this->render('archive/run.html.twig', ['run' => $run, 'error' => null]);
    }

    #[Route('/games/{id}', name: 'app_archive_game', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['GET'])]
    public function game(int $id, CrawlClient $crawl): Response
    {
        try {
            $game = $crawl->game($id);
        } catch (ArchiveUnavailable $error) {
            return $this->render('archive/game.html.twig', ['game' => null, 'error' => $error->getMessage()], new Response(status: $error->status));
        }

        return $this->render('archive/game.html.twig', ['game' => $game, 'error' => null]);
    }

    private function date(string $value): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Choose valid dates.');
        }

        return $date->getTimestamp();
    }
}
