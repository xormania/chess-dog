<?php

declare(strict_types=1);

namespace App\Archive;

use App\Archive\Model\AuthorizedSubscription;
use App\Archive\Model\ValidatedEvent;

/** Converts untrusted JSON hints into resource identities; never returns HTML or renders content. */
final readonly class EventValidator
{
    public function __construct(private TopicNames $topics)
    {
    }

    public function validate(string $json, AuthorizedSubscription $subscription): ValidatedEvent
    {
        try {
            if (strlen($json) > 65536) {
                throw new \InvalidArgumentException();
            }
            $envelope = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
            if (!$envelope instanceof \stdClass || !($envelope->counters ?? null) instanceof \stdClass) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            $kind = match ($data['type'] ?? null) {
                'job.updated' => 'jobs', 'run.updated' => 'runs', default => throw new \InvalidArgumentException(),
            };
            $idField = 'jobs' === $kind ? 'job_id' : 'run_id';
            $id = $data[$idField] ?? null;
            if (1 !== ($data['schema_version'] ?? null) || ($data['workspace_id'] ?? null) !== $subscription->workspaceId
                || !is_string($data['archive_id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $data['archive_id'])
                || !is_string($data['event_id'] ?? null)
                || !preg_match('/\Aurn:chess-crawl:'.$data['archive_id'].':[1-9][0-9]*\z/', $data['event_id'])
                || !is_int($id) || $id < 1 || !is_int($data['revision'] ?? null) || $data['revision'] < 1
                || !is_int($data['occurred_at'] ?? null) || $data['occurred_at'] < 0
                || !in_array($this->topics->resource($subscription->workspaceId, $kind, $id), $subscription->topics, true)
                || !is_string($data['status'] ?? null) || !preg_match('/\A[a-z_]{1,64}\z/', $data['status'])
                || !is_string($data['provider'] ?? null)
                || !in_array($data['provider'], ['chess.com', 'lichess'], true)
                || !is_array($data['counters'] ?? null)) {
                throw new \InvalidArgumentException();
            }
            foreach ($data['counters'] as $counter) {
                if (!is_int($counter) || $counter < 0) {
                    throw new \InvalidArgumentException();
                }
            }
            if ('jobs' === $kind && (!is_string($data['kind'] ?? null)
                || !preg_match('/\A[a-z_]{1,64}\z/', $data['kind'])
                || !array_key_exists('run_id', $data)
                || !is_int($data['counters']['attempts'] ?? null) || !is_int($data['counters']['retries'] ?? null)
                || !array_key_exists('next_attempt_at', $data)
                || (null !== $data['next_attempt_at'] && ((!is_int($data['next_attempt_at']) && !is_float($data['next_attempt_at']))
                    || !is_finite((float) $data['next_attempt_at']) || $data['next_attempt_at'] < 0)))) {
                throw new \InvalidArgumentException();
            }
            $runId = 'runs' === $kind ? $id : ($data['run_id'] ?? null);
            if (null !== $runId && (!is_int($runId) || $runId < 1)) {
                throw new \InvalidArgumentException();
            }

            return new ValidatedEvent($data['archive_id'], $data['event_id'], $kind, $id, $data['revision'], $runId);
        } catch (\JsonException|\InvalidArgumentException|\TypeError) {
            throw new \InvalidArgumentException('Invalid archive event.');
        }
    }
}
