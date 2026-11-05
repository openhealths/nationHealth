# eHealth request limits

## Configured groups

The official [Rate Limits](https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583761951)
table lists employee GET list/detail quotas of 30 requests per minute **by token**.
The application shares one conservative limit of 29 requests between
`/api/employees` and `/api/employees/{id}`.

The PREPROD Medical Events 9.15.6
[job details contract](https://docs-preprod.ehealth.gov.ua/#medical-events/tag/medical-events/GET/api/jobs/{id})
documents pending responses without a numeric job quota. The checked public Rate
Limits table also has no jobs entry. The default of 60 job GETs per minute is an
application setting, not a claimed ESOZ quota. It can be changed with
`EHEALTH_REMOTE_JOB_REQUESTS_PER_MINUTE` after confirming the environment's quota.
The former value of 1399 had no verified source in these documents.

Every group in `ehealth.rate_limit` can opt into HTTP accounting by defining
`methods`, anchored `paths`, `max_requests` and `window_seconds`. For example, a
new independently verified group could be configured without another limiter class:

```php
'custom_read' => [
    'methods' => ['GET'],
    'paths' => ['~^/api/custom_resources(?:/[^/]+)?$~i'],
    'max_requests' => 10, // Example only; use the verified quota for the real resource.
    'window_seconds' => 30,
],
```

The pattern allows a list and one identifier, but excludes nested action URLs.
Group names identify a shared quota: two paths in one group spend the same count.
The two enabled groups are `employee` and `remote_job`; existing scalar limits
and minute/hour policies for other resources keep their current behavior. The
existing employee/job queue middleware reads the same count and window settings.

## What happens before a request

`EHealthRequest` selects a group after Laravel resolves the URL and finishes its
`beforeSending` callbacks. It uses the actual API host and a SHA-256 hash of the
final bearer token. Scheme spelling such as `Bearer` versus `bearer` does not split
the same token into different quotas. Multiple Authorization values are rejected
before sending because the server's token choice would be ambiguous.

For each HTTP attempt, the client calls
`EHealthRateLimiter::reserve($key, $maxRequests, $windowSeconds)`. This includes
Laravel retries, redirects and fallback URLs, rather than just the initial client
method call. The limiter does not know employee/job paths or local user IDs.

Inside a shared cache lock, it removes expired request times, checks the count
and records this attempt. An unavailable quota raises `EHealthRateLimitedException`
with the wait in seconds, without sending HTTP or fabricating a server response.
The integer timestamp calculation may wait up to one extra second to avoid
admitting a request fractionally early. A busy lock waits for up to one second;
if it is still busy, the request is deferred without being counted or sent.

The timestamp list is intentional. Laravel's ordinary counter expires a whole
window together and can allow two full bursts around that boundary. Here every
request expires separately. For example, a fixed counter started by one request
at 12:00:00 can accept the remaining 28 at 12:00:59 and reset at 12:01:00. The
rolling list still counts those 28 recent requests, so another full burst is blocked.

## Laravel reuse and extension

`EHealthRateLimiter` extends `Illuminate\Cache\RateLimiter`. It reuses Laravel's
`hit`, `clear` and `availableIn` methods for the server cooldown timer. Rolling
request times use a separate `:requests` key, so inherited counter methods do not
collide with timestamp data. The application's standard RateLimiter binding is
unchanged; the eHealth binding uses the same `cache.limiter` store selection.

The class is not final. A service can bind its own subclass to
`EHealthRateLimiter::class`. `recordRequest` is protected; the client's
`rateLimitGroup`, `rateLimitKey` and `reserveRequest` hooks are also protected.
Normal endpoint additions need only configuration, not subclasses.

All workers and UI instances must share a cache store with reliable atomic locks.
Use shared Redis or database cache for multi-host deployments. An `array` store
is process-local; network or Docker bind-mounted filesystems must not be assumed
to provide reliable cross-process file locks. The concurrency regression uses
local temporary storage inside the PHP runtime, not the Mac bind mount.

## Server Retry-After and queue behavior

An HTTP 429 calls `cooldown($key, $seconds)` before Laravel decides whether to
retry. Integer seconds and HTTP-date `Retry-After` values are supported; missing
or invalid values default to 60 seconds. A later 20-second response does not
shorten a previously recorded 90-second wait. Longer waits are not capped at the
300-second pending-poll interval.

A lock timeout while recording the cooldown must not replace the real HTTP 429.
The current caller still receives its response and full `Retry-After`; a local
copy of the deadline also prevents this process's HTTP retries from resending
immediately. A warning records that this cooldown could not be shared. Other
processes can only observe a cooldown after it is successfully saved in the shared
store; no unlocked overwrite is attempted.

Queued 429s and local capacity waits release the job for the required delay. They
do not rotate to another user's token or count as unhandled exceptions. Jobs may
be attempted indefinitely for capacity/pending waits, while Laravel's
`maxExceptions = 3` still limits unhandled failures, including worker timeouts.
Remote link polling keeps its 5-to-300-second pending backoff and all confirmation
guards. A worker outage no longer expires a fresh poll after 24 hours. A permanently
pending remote job may continue polling every five minutes and remains unconfirmed.

Previously serialized queue payloads retain their original `maxTries` and
`retryUntil`. This change does not rewrite, delete or replay queued/failed jobs.
Recovery must dispatch a fresh poll **for the existing remote job** with valid
authorized context, without resubmitting the signed operation.
