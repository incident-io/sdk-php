<?php

declare(strict_types=1);

namespace IncidentIo\Tests;

use GuzzleHttp\Psr7\Response;
use IncidentIo\Api\ActionsV1Api;
use IncidentIo\Api\AlertEventsV2Api;
use IncidentIo\Api\AlertsV2Api;
use IncidentIo\Api\PayReportsV2Api;
use IncidentIo\Api\SeveritiesV1Api;
use IncidentIo\ApiException;
use IncidentIo\Configuration;
use IncidentIo\Model\ActionV1;
use IncidentIo\Model\ActionsListResultV1;
use IncidentIo\Model\AlertEventsCreateHTTPPayloadV2;
use IncidentIo\Model\SeveritiesListResultV1;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Behaviour of the generated client, and of what scripts/fix_generated.py
 * changes in it, against a mocked HTTP handler. No API key needed.
 *
 * The filter encoding is tested in ReadmeTest, against the README's example.
 */
final class ClientTest extends TestCase
{
    use MocksHttp;

    /**
     * An Api class wired to a mock handler, the way the README tells callers
     * to construct one, so the auth and base URL wiring is what gets tested.
     *
     * @template T
     * @param class-string<T> $api
     * @return T
     */
    private function api(string $api, Response ...$responses): object
    {
        return new $api($this->client(...$responses), (new Configuration())->setAccessToken('secret-key'));
    }

    public function testSendsTheBearerTokenAndUserAgentToTheApi(): void
    {
        $this->api(SeveritiesV1Api::class, self::json(200, ['severities' => []]))->severitiesV1List();

        $request = $this->lastRequest();
        $this->assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('https://api.incident.io/v1/severities', (string) $request->getUri());
        $this->assertStringStartsWith('incident-io-sdk-php/', $request->getHeaderLine('User-Agent'));
    }

    public function testDeserializesAResponse(): void
    {
        $result = $this->api(SeveritiesV1Api::class, self::json(200, ['severities' => [[
            'id' => '01FH5TZRWMNAFB0DZ23FD1TV96',
            'name' => 'Minor',
            'description' => 'Issues with low impact.',
            'rank' => 1,
            'created_at' => '2021-08-17T13:28:57.801578Z',
            'updated_at' => '2021-08-17T14:28:57.801578Z',
        ]]]))->severitiesV1List();

        $this->assertInstanceOf(SeveritiesListResultV1::class, $result);
        $severity = $result->getSeverities()[0];
        $this->assertSame('Minor', $severity->getName());
        $this->assertSame(1, $severity->getRank());
        $this->assertSame('2021-08-17', $severity->getCreatedAt()->format('Y-m-d'));
    }

    public function testThrowsOnAnErrorResponse(): void
    {
        $api = $this->api(SeveritiesV1Api::class, self::json(422, [
            'type' => 'validation_error',
            'status' => 422,
            'request_id' => 'abc',
            'errors' => [['code' => 'is_required', 'message' => 'A name is required']],
        ]));

        try {
            $api->severitiesV1List();
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('validation_error', (string) $e->getResponseBody());
        }
    }

    public function testTheAsyncVariantResolvesToTheSameResult(): void
    {
        $result = $this->api(SeveritiesV1Api::class, self::json(200, ['severities' => []]))
            ->severitiesV1ListAsync()
            ->wait();

        $this->assertInstanceOf(SeveritiesListResultV1::class, $result);
    }

    public function testKeepsAnEnumValueTheSdkDoesNotKnow(): void
    {
        $result = $this->api(ActionsV1Api::class, self::json(200, ['actions' => [[
            'id' => '01',
            'status' => 'some_future_status',
        ]]]))->actionsV1List();

        $this->assertInstanceOf(ActionsListResultV1::class, $result);
        $this->assertSame('some_future_status', $result->getActions()[0]->getStatus());
    }

    public function testKnownEnumValuesAreUnaffected(): void
    {
        $action = new ActionV1(['status' => ActionV1::STATUS_COMPLETED]);

        $this->assertSame('completed', $action->getStatus());
        $this->assertContains('completed', ActionV1::getStatusAllowableValues());
        $this->assertSame([], array_filter(
            $action->listInvalidProperties(),
            fn (string $problem) => str_contains($problem, 'status'),
        ));
    }

    public function testAcceptsANewerEnumValueOnARequestModel(): void
    {
        // A caller on an older SDK must be able to send a value the API added
        // since, and it must go out as written.
        $action = (new ActionV1())->setStatus('some_future_status');

        $this->assertSame('some_future_status', $action->getStatus());
    }

    public function testEncodesADeepObjectParameterWithScalarValues(): void
    {
        $this->api(AlertEventsV2Api::class, self::json(202, ['status' => 'success']))->alertEventsV2CreateHTTP(
            alertSourceConfigId: '01SOURCE',
            alertEventsCreateHTTPPayloadV2: new AlertEventsCreateHTTPPayloadV2(['title' => 't', 'status' => 'firing']),
            query: ['region' => 'eu', 'env' => 'prod'],
        );

        $this->assertSame('query[region]=eu&query[env]=prod', urldecode($this->lastRequest()->getUri()->getQuery()));
    }

    public function testLeavesScalarQueryParametersAlone(): void
    {
        $this->api(AlertsV2Api::class, self::json(200, ['alerts' => []]))->alertsV2List(pageSize: 10, after: '01CURSOR');

        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame('10', $query['page_size']);
        $this->assertSame('01CURSOR', $query['after']);
    }

    public function testABinaryDownloadReturnsItsBytes(): void
    {
        $file = $this->api(
            PayReportsV2Api::class,
            new Response(200, ['Content-Type' => 'text/csv'], "user,hours\nlisa,12\n"),
        )->payReportsV2Download('01REPORT');

        $this->assertInstanceOf(\SplFileObject::class, $file);
        $this->assertSame("user,hours\nlisa,12\n", file_get_contents($file->getPathname()));
    }

    public function testMarksDeprecatedOperations(): void
    {
        foreach (['actionsV1List', 'actionsV1ListWithHttpInfo', 'actionsV1ListAsync', 'actionsV1ListAsyncWithHttpInfo'] as $method) {
            $this->assertStringContainsString('@deprecated', (string) (new ReflectionMethod(ActionsV1Api::class, $method))->getDocComment());
        }
        $this->assertStringNotContainsString('@deprecated', (string) (new ReflectionMethod(SeveritiesV1Api::class, 'severitiesV1List'))->getDocComment());
    }
}
