# incident.io PHP SDK

[![Packagist](https://img.shields.io/packagist/v/incident-io/sdk-php)](https://packagist.org/packages/incident-io/sdk-php)

A PHP client for the [incident.io API](https://api-docs.incident.io/). It is generated automatically from our published OpenAPI schema, and a new version is released whenever the schema changes.

## Install

```sh
composer require incident-io/sdk-php
```

Requires PHP 8.1 or later, with the `curl`, `json` and `mbstring` extensions. HTTP goes through [Guzzle 7](https://docs.guzzlephp.org/).

## Quickstart

Create an API key in the incident.io dashboard under **Settings → API keys**, then:

```php
use IncidentIo\Api\IncidentsV2Api;
use IncidentIo\Configuration;

$config = (new Configuration())->setAccessToken(getenv('INCIDENT_API_KEY'));
$incidents = new IncidentsV2Api(config: $config);

$result = $incidents->incidentsV2List(pageSize: 25);
foreach ($result->getIncidents() as $incident) {
    echo $incident->getReference(), ' ', $incident->getName(), "\n";
}
```

There is one class per API group under `IncidentIo\Api`, named for the group and its version (`IncidentsV2Api`, `SeveritiesV1Api`, `CatalogV3Api`), and one class per request and response shape under `IncidentIo\Model`. Each operation is a method named for the operation, in four forms:

| Method | Returns |
| --- | --- |
| `incidentsV2List(...)` | The response model |
| `incidentsV2ListWithHttpInfo(...)` | `[$model, $statusCode, $headers]` |
| `incidentsV2ListAsync(...)` | A Guzzle `PromiseInterface` resolving to the model |
| `incidentsV2ListAsyncWithHttpInfo(...)` | A promise resolving to `[$model, $statusCode, $headers]` |

Pass parameters by name, as above. The API adds optional parameters in minor releases, and a new one can land before those you use, which moves them. Named arguments are unaffected; positional calls are not.

Models take an array of properties, keyed by the camelCase property name:

```php
use IncidentIo\Api\IncidentsV2Api;
use IncidentIo\Model\IncidentsCreatePayloadV2;

$incident = $incidents->incidentsV2Create(new IncidentsCreatePayloadV2([
    'idempotencyKey' => 'deploy-2026-09-23-01',
    'name' => 'Checkout is returning 500s',
    'severityId' => '01FH5TZRWMNAFB0DZ23FD1TV96',
    'visibility' => IncidentsCreatePayloadV2::VISIBILITY__PUBLIC,
]))->getIncident();
```

## Errors

A response outside 2xx throws `IncidentIo\ApiException`. Its code is the HTTP status, and the body is the API's JSON error:

```php
use IncidentIo\ApiException;

try {
    $incidents->incidentsV2Show(id: '01NOTREAL');
} catch (ApiException $e) {
    echo $e->getCode(), "\n";           // 404
    echo $e->getResponseBody(), "\n";   // {"type":"not_found","status":404,"request_id":"...","errors":[...]}
}
```

A network failure also throws `ApiException`, with code 0.

## Pagination

List endpoints return a page and a cursor. Pass `paginationMeta.after` back as `after` until it is empty:

```php
$after = null;
do {
    $page = $incidents->incidentsV2List(pageSize: 100, after: $after);
    foreach ($page->getIncidents() as $incident) {
        // ...
    }
    $after = $page->getPaginationMeta()?->getAfter();
} while ($after !== null && $after !== '');
```

## Filters

Filter parameters take an operator and a list of values, as an array:

```php
$incidents->incidentsV2List(
    statusCategory: ['one_of' => ['live', 'learning']],
    severity: ['gte' => ['01FH5TZRWMNAFB0DZ23FD1TV96']],
    customField: ['01FCNDV6P870EA6S7TK1DSYDG0' => ['one_of' => ['01FCNDV6P870EA6S7TK1DSYDG1']]],
);
```

This is sent as `status_category[one_of]=live&status_category[one_of]=learning` and so on. Each parameter's description lists the operators it accepts.

## Enums

Enum values are constants on the model that uses them, such as `ActionV1::STATUS_COMPLETED`, and `ActionV1::getStatusAllowableValues()` lists them. The properties themselves are plain strings.

The API adds enum values without a new version, so the SDK accepts values it doesn't know. A response with a value newer than your SDK deserializes normally and keeps the value as sent, and you can set a newer value on a request model. Match on the constants you care about and handle the rest:

```php
use IncidentIo\Model\ActionV1;

match ($action->getStatus()) {
    ActionV1::STATUS_OUTSTANDING => 'open',
    ActionV1::STATUS_COMPLETED, ActionV1::STATUS_NOT_DOING => 'done',
    default => 'unknown',
};
```

## Configuration

`Configuration` holds the API key, the base URL (`setHost`, default `https://api.incident.io`) and the user agent (`setUserAgent`, default `incident-io-sdk-php/<version>`). The first argument to every Api class is a Guzzle `ClientInterface`, so timeouts, proxies and middleware are Guzzle options:

```php
use GuzzleHttp\Client;

$incidents = new IncidentsV2Api(new Client(['timeout' => 10]), $config);
```

### Retries

The SDK does not retry. The API allows 1200 requests a minute per key, and answers `429 Too Many Requests` with a `Retry-After` header, in seconds, when you exceed it. Prefer `Retry-After` over `X-RateLimit-Reset` when deciding how long to wait. Guzzle's retry middleware does the rest:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$stack = HandlerStack::create();
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

$incidents = new IncidentsV2Api(new Client(['handler' => $stack]), $config);
```

### Endpoints that don't take an API key

Four operations don't use the API key. `utilitiesV1IPRanges` and `utilitiesV1OpenAPIV3` are public. `alertEventsV2CreateHTTP` and `heartbeatV2Ping` authenticate with the secret configured on the alert source, which you pass yourself:

```php
use IncidentIo\Api\AlertEventsV2Api;
use IncidentIo\Model\AlertEventsCreateHTTPPayloadV2;

(new AlertEventsV2Api())->alertEventsV2CreateHTTP(
    alertSourceConfigId: '01GW2G3V0S59R238FAHPDS1R66',
    alertEventsCreateHTTPPayloadV2: new AlertEventsCreateHTTPPayloadV2([
        'title' => 'Disk almost full',
        'status' => AlertEventsCreateHTTPPayloadV2::STATUS_FIRING,
        'deduplicationKey' => 'disk-db-1',
    ]),
    authorization: 'Bearer ' . getenv('ALERT_SOURCE_SECRET'),
);
```

### Deprecated endpoints

Operations the API has deprecated are marked `@deprecated`, which IDEs and static analysers pick up. They keep working until the API removes them, but newer versions of the same group exist; don't assume the highest version number is the current one without checking the [API docs](https://api-docs.incident.io/).

## Versioning

Releases follow SemVer and are cut automatically. Every schema change that adds to the API is a minor release. A change that would break existing code is never released automatically: it opens an issue here, and a person decides on a major version.

## Support

Report problems with the SDK as [issues](https://github.com/incident-io/sdk-php/issues). For the API itself, see the [API docs](https://api-docs.incident.io/) or contact support@incident.io.

`src/` is generated, so please don't open pull requests that edit it. See [CONTRIBUTING.md](CONTRIBUTING.md) for how the generation works.

## License

MIT. The generated code is produced by [OpenAPI Generator](https://openapi-generator.tech), which is Apache 2.0.
