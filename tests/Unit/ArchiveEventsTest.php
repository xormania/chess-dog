<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Archive\EventValidator;
use App\Archive\Model\AuthorizedSubscription;
use App\Archive\Model\EventCursor;
use App\Archive\RevisionGate;
use App\Archive\TopicNames;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class ArchiveEventsTest extends TestCase
{
    public function testSnapshotRevisionRejectsDuplicatesAndOlderEvents(): void
    {
        $gate = new RevisionGate();
        $cursor = new EventCursor(str_repeat('a', 32), 'jobs', 9, 3);
        foreach ([1, 3] as $revision) {
            self::assertSame('ignore', $gate->action($this->event(['revision' => $revision]), $cursor));
        }
        foreach ([4, 20] as $revision) {
            self::assertSame('refresh', $gate->action($this->event(['revision' => $revision]), $cursor));
        }
        self::assertSame(3, $cursor->revision); // Receipt never advances authoritative snapshot state.
        self::assertSame('refresh', $gate->onReconnect());
    }

    public function testArchiveReplacementForcesRefreshEvenAtOlderRevision(): void
    {
        $archive = str_repeat('b', 32);
        $event = $this->event(['archive_id' => $archive, 'event_id' => 'urn:chess-crawl:'.$archive.':2', 'revision' => 1]);
        self::assertSame('refresh', (new RevisionGate())->action($event, new EventCursor(str_repeat('a', 32), 'jobs', 9, 10)));
    }

    public function testRejectsCrossWorkspaceResourceSchemaAndEventIdentity(): void
    {
        foreach ([['workspace_id' => 'other'], ['job_id' => 10], ['schema_version' => 2], ['event_id' => 'urn:chess-crawl:'.str_repeat('b', 32).':1'], ['revision' => '4'], ['revision' => 0], ['counters' => ['retries' => -1]], ['counters' => [1, 2]], ['kind' => '<script>'], ['next_attempt_at' => '123'], ['type' => 'turbo-stream'], ['provider' => 'unknown']] as $change) {
            try {
                $this->event($change);
                self::fail('Invalid event accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid archive event.', $error->getMessage());
            }
        }
    }

    public function testValidatedEventExposesOnlyRefreshIdentityAndRunHint(): void
    {
        $event = $this->event(['html' => '<script>secret</script>']);
        self::assertSame(7, $event->runId);
        self::assertFalse(property_exists($event, 'html'));
        self::assertFalse(property_exists($event, 'counters'));
    }

    public function testNormalJobEnvelopeAcceptsNullRetrySchedule(): void
    {
        foreach (['pending', 'in_progress', 'done'] as $status) {
            $event = $this->event(['status' => $status, 'next_attempt_at' => null]);
            self::assertSame(9, $event->resourceId);
            self::assertSame(4, $event->revision);
        }
    }

    public function testRunEnvelopeAcceptsEmptyObjectCounters(): void
    {
        $subscription = new AuthorizedSubscription('local', 'https://dog.example/.well-known/mercure',
            ['https://chess-crawl.local/workspaces/local/runs/7'], [], Cookie::create('mercureAuthorization', 'unused'));
        $event = (new EventValidator(new TopicNames('https://chess-crawl.local')))->validate(json_encode([
            'schema_version' => 1, 'archive_id' => str_repeat('a', 32), 'event_id' => 'urn:chess-crawl:'.str_repeat('a', 32).':101',
            'workspace_id' => 'local', 'type' => 'run.updated', 'run_id' => 7, 'revision' => 1,
            'occurred_at' => 123, 'status' => 'running', 'provider' => 'lichess', 'counters' => new \stdClass(),
        ], JSON_THROW_ON_ERROR), $subscription);
        self::assertSame('runs', $event->kind);
        self::assertSame(7, $event->resourceId);
        self::assertSame('ignore', (new RevisionGate())->action($event, new EventCursor(str_repeat('a', 32), 'runs', 7, 1)));
    }

    public function testInvalidJsonAndOversizedEventsAreRejected(): void
    {
        foreach (['[]', '{invalid', str_repeat('x', 65537)] as $json) {
            try {
                (new EventValidator(new TopicNames('https://chess-crawl.local')))->validate($json, $this->subscription());
                self::fail('Invalid event JSON accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid archive event.', $error->getMessage());
            }
        }
    }

    private function event(array $change = []): \App\Archive\Model\ValidatedEvent
    {
        return (new EventValidator(new TopicNames('https://chess-crawl.local')))->validate(json_encode(array_replace([
            'schema_version' => 1, 'archive_id' => str_repeat('a', 32), 'event_id' => 'urn:chess-crawl:'.str_repeat('a', 32).':100',
            'workspace_id' => 'local', 'type' => 'job.updated', 'job_id' => 9, 'run_id' => 7, 'revision' => 4,
            'occurred_at' => 123, 'status' => 'done', 'provider' => 'lichess', 'kind' => 'fetch_user_games',
            'counters' => ['attempts' => 1, 'retries' => 0], 'next_attempt_at' => 0,
        ], $change), JSON_THROW_ON_ERROR), $this->subscription());
    }

    private function subscription(): AuthorizedSubscription
    {
        return new AuthorizedSubscription('local', 'https://dog.example/.well-known/mercure',
            ['https://chess-crawl.local/workspaces/local/jobs/9'], [], Cookie::create('mercureAuthorization', 'unused'));
    }
}
