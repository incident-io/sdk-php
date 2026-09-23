<?php

declare(strict_types=1);

namespace IncidentIo\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use IncidentIo\Api\AlertEventsV2Api;
use IncidentIo\Api\IncidentsV2Api;
use IncidentIo\ApiException;
use IncidentIo\Configuration;
use IncidentIo\Model\ActionV1;
use IncidentIo\Model\AlertEventsCreateHTTPPayloadV2;
use IncidentIo\Model\IncidentsCreatePayloadV2;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Every example in README.md, run against a mocked HTTP handler.
 *
 * PHP has no compile step, so a README example that names a method, argument
 * or constant that does not exist would otherwise only be found by a reader.
 * When you change an example in the README, change it here too.
 */
final class ReadmeTest extends TestCase
{
    use MocksHttp;

    private static function incident(string $id): array
    {
        return ['id' => $id, 'reference' => 'INC-1', 'name' => 'Checkout is returning 500s'];
    }

    public function testQuickstart(): void
    {
        $config = (new Configuration())->setAccessToken('key');
        $incidents = new IncidentsV2Api($this->client(self::json(200, ['incidents' => [self::incident('01A')]])), $config);

        $result = $incidents->incidentsV2List(pageSize: 25);
        $lines = [];
        foreach ($result->getIncidents() as $incident) {
            $lines[] = $incident->getReference() . ' ' . $incident->getName();
        }

        $this->assertSame(['INC-1 Checkout is returning 500s'], $lines);
    }

    public function testCreatingWithAPayloadModel(): void
    {
        $incidents = new IncidentsV2Api($this->client(self::json(201, ['incident' => self::incident('01A')])), new Configuration());

        $incident = $incidents->incidentsV2Create(new IncidentsCreatePayloadV2([
            'idempotencyKey' => 'deploy-2026-09-23-01',
            'name' => 'Checkout is returning 500s',
            'severityId' => '01FH5TZRWMNAFB0DZ23FD1TV96',
            'visibility' => IncidentsCreatePayloadV2::VISIBILITY__PUBLIC,
        ]))->getIncident();

        $this->assertSame('01A', $incident->getId());
        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame([
            'idempotency_key' => 'deploy-2026-09-23-01',
            'name' => 'Checkout is returning 500s',
            'severity_id' => '01FH5TZRWMNAFB0DZ23FD1TV96',
            'visibility' => 'public',
        ], $sent);
    }

    public function testErrors(): void
    {
        $incidents = new IncidentsV2Api($this->client(self::json(404, ['type' => 'not_found', 'status' => 404])), new Configuration());

        try {
            $incidents->incidentsV2Show(id: '01NOTREAL');
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertStringContainsString('"not_found"', (string) $e->getResponseBody());
        }
    }

    public function testPagination(): void
    {
        $incidents = new IncidentsV2Api($this->client(
            self::json(200, ['incidents' => [self::incident('01A')], 'pagination_meta' => ['after' => '01A', 'page_size' => 1]]),
            self::json(200, ['incidents' => [self::incident('01B')], 'pagination_meta' => ['page_size' => 1]]),
        ), new Configuration());

        $seen = [];
        $after = null;
        do {
            $page = $incidents->incidentsV2List(pageSize: 100, after: $after);
            foreach ($page->getIncidents() as $incident) {
                $seen[] = $incident->getId();
            }
            $after = $page->getPaginationMeta()?->getAfter();
        } while ($after !== null && $after !== '');

        $this->assertSame(['01A', '01B'], $seen);
        $this->assertStringContainsString('after=01A', $this->history[1]['request']->getUri()->getQuery());
    }

    public function testFilters(): void
    {
        $incidents = new IncidentsV2Api($this->client(self::json(200, ['incidents' => []])), new Configuration());

        $incidents->incidentsV2List(
            statusCategory: ['one_of' => ['live', 'learning']],
            severity: ['gte' => ['01FH5TZRWMNAFB0DZ23FD1TV96']],
            customField: ['01FCNDV6P870EA6S7TK1DSYDG0' => ['one_of' => ['01FCNDV6P870EA6S7TK1DSYDG1']]],
        );

        $query = urldecode($this->lastRequest()->getUri()->getQuery());
        $this->assertStringContainsString('status_category[one_of]=live&status_category[one_of]=learning', $query);
        $this->assertStringContainsString('severity[gte]=01FH5TZRWMNAFB0DZ23FD1TV96', $query);
        $this->assertStringContainsString('custom_field[01FCNDV6P870EA6S7TK1DSYDG0][one_of]=01FCNDV6P870EA6S7TK1DSYDG1', $query);
    }

    public function testMatchingOnEnums(): void
    {
        $describe = fn (ActionV1 $action) => match ($action->getStatus()) {
            ActionV1::STATUS_OUTSTANDING => 'open',
            ActionV1::STATUS_COMPLETED, ActionV1::STATUS_NOT_DOING => 'done',
            default => 'unknown',
        };

        $this->assertSame('done', $describe(new ActionV1(['status' => 'completed'])));
        $this->assertSame('unknown', $describe(new ActionV1(['status' => 'some_future_status'])));
    }

    public function testConfiguration(): void
    {
        $config = (new Configuration())->setAccessToken('key');
        $incidents = new IncidentsV2Api(new Client(['timeout' => 10]), $config);

        $this->assertSame('https://api.incident.io', $incidents->getConfig()->getHost());
        $this->assertStringStartsWith('incident-io-sdk-php/', $incidents->getConfig()->getUserAgent());
    }

    public function testRetries(): void
    {
        $mock = new MockHandler([
            self::json(429, ['type' => 'too_many_requests'], ['Retry-After' => '0']),
            self::json(200, ['incidents' => []]),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::retry(
            function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $error): bool {
                if ($retries >= 4) {
                    return false;
                }
                return $error !== null || ($response !== null && ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500));
            },
            function (int $retries, ?ResponseInterface $response): int {
                $retryAfter = $response?->getHeaderLine('Retry-After');
                return $retryAfter !== null && ctype_digit($retryAfter)
                    ? (int) $retryAfter * 1000
                    : min(30_000, 1000 * 2 ** ($retries - 1));
            },
        ));

        $incidents = new IncidentsV2Api(new Client(['handler' => $stack]), new Configuration());
        $incidents->incidentsV2List();

        $this->assertSame(0, $mock->count(), 'both responses were consumed, so the 429 was retried');
    }

    public function testAlertEventsAuthenticateWithTheSourceSecret(): void
    {
        $config = (new Configuration())->setAccessToken('api-key-not-used-here');
        (new AlertEventsV2Api($this->client(self::json(202, ['status' => 'success', 'message' => 'Event accepted for processing'])), $config))->alertEventsV2CreateHTTP(
            alertSourceConfigId: '01GW2G3V0S59R238FAHPDS1R66',
            alertEventsCreateHTTPPayloadV2: new AlertEventsCreateHTTPPayloadV2([
                'title' => 'Disk almost full',
                'status' => AlertEventsCreateHTTPPayloadV2::STATUS_FIRING,
                'deduplicationKey' => 'disk-db-1',
            ]),
            authorization: 'Bearer source-secret',
        );

        $request = $this->lastRequest();
        $this->assertSame('Bearer source-secret', $request->getHeaderLine('Authorization'));
        $this->assertSame('/v2/alert_events/http/01GW2G3V0S59R238FAHPDS1R66', $request->getUri()->getPath());
    }
}
