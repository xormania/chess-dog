# Archive services

This increment adds backend services and configuration only. The existing LAST
preview remains a demonstration. It does not submit jobs, connect an event
stream, or implement account authentication. Live Components and Turbo are
already installed; Mercure Bundle now supplies the subscription token factory.
Component design, navigation, user flows, and application routes remain open.

## Trusted local configuration

Put installation credentials in ignored `.env.local`, Symfony secrets, or the
deployment's secret manager. Never place them in Twig, JavaScript, Live props,
browser storage, URLs, or a committed environment file.

| Variable | Meaning |
| --- | --- |
| `CHESS_CRAWL_API_BASE_URL` | Fixed API origin, without a path, query or credentials. HTTPS is required except for loopback HTTP development. |
| `CHESS_CRAWL_API_TOKEN` | Server Bearer token provisioned by Crawl for this workspace. |
| `CHESS_CRAWL_WORKSPACE_ID` | Expected server workspace, `local` for Crawl's single-token configuration. This is a consistency check, not authentication. |
| `CHESS_CRAWL_TOPIC_PREFIX` | Crawl's logical topic prefix; normally `https://chess-crawl.local`, independent of the hub address. |
| `MERCURE_URL` | Internal hub endpoint. Dog has no publisher grant. Crawl owns event publication. |
| `MERCURE_PUBLIC_URL` | Same-origin browser endpoint, usually `https://dog.example/.well-known/mercure` through a reverse proxy to Crawl's hub. |
| `MERCURE_SUBSCRIBER_JWT_SECRET` | At least 32 bytes, matching the hub's subscriber HMAC key. Use a separate publisher key. Empty by default; subscriptions fail closed. |

Configure the reverse proxy and trusted hosts for the actual deployment. The
service requires an exact scheme/host/port match between the app request and the
public hub URL, and creates a host-only, HttpOnly, SameSite=Strict cookie scoped
to the hub path. HTTPS cookies are Secure. Tokens expire after five minutes.
Cross-origin cookie/CORS support and alternate signing algorithms are outside
this foundation. No reverse proxy or browser connection is added by this PR.

The default `ConfiguredArchiveContextProvider` represents a trusted single
operator installation. **It does not authenticate app users.** Do not expose
submission or subscription methods through public endpoints using this provider.
A SaaS integration must replace `ArchiveContextProvider` with a resolver that
maps the authenticated application principal to an authorized workspace and
server-held credential, consistently for the whole request. A workspace ID
from a form, URL, Live prop, or `X-Workspace-ID` header cannot establish authority.
Crawl's existing server credential mapping remains the enforcement boundary.
Shared provider/game archive data is not a private workspace dataset.

## Service contract

`CrawlClient` has explicit methods for stored player, games, coverage, game, run,
and job reads. Reads never start acquisition. It validates identifiers, disables
redirects, bounds response size and duration, and produces sanitized exceptions
without upstream bodies or exception chains. Run/job reads reject mismatched
workspace, identity, archive ID, and revision metadata.
Stored archive reads also verify the token-to-workspace binding through
`/v1/workspace` before fetching data: rich player envelopes can contain private
resource observations. One resolved context is used for both calls, and no
binding result is shared or cached across users.

`submitImport()` accepts `max_games`, `collection_mode`, `batch_size`, and optional
Unix-second `since`/`until`. Modes are `bounded`, `full`, `incremental`, and
`backfill`; bounded requests require both dates. Omit `since` for incremental
watermark resume. `submitCrawl()` requires dates and explicit discovery budgets:
`max_games`, `max_depth`, `max_users`, and `max_jobs`. Backend policy remains
authoritative for maximum budgets. Unknown fields, including workspace selectors,
are rejected. Both methods verify `/v1/workspace` against trusted configuration
before submitting, and forward the caller's stable idempotency key. Retry a
failed/uncertain submission with the same key and identical parameters.

`CrawlSubscriptions::forRun()` and `forJob()` first read the requested resource
through the scoped API. Each grants one exact topic, never a workspace wildcard
or publishing capability. A run grant does not enumerate its jobs into a cookie;
large runs remain bounded. Add a separate authorized job subscription when a
future component needs that job's progress. The returned object contains:

- `connectionOptions()`: browser-safe public URL, exact topics, and credentials flag;
- `cookie()`: attach to an authorized response; never serialize it or expose its JWT;
- `cursors`: snapshot archive/resource/revision metadata for the future consumer.

Private topic identifiers are
`{prefix}/workspaces/{workspace}/runs/{id}` and
`{prefix}/workspaces/{workspace}/jobs/{id}`. Crawl has no endpoint that mints
subscriber credentials, so Dog signs narrowly granted subscriber JWTs with its
server-only subscriber key. Do not use Crawl's broadly granted development
subscriber JWT as a browser credential.

## Event consumption for future components

`EventValidator` checks the schema-version-1 envelope, workspace, authorized topic,
archive identity, event identity, resource, revision, timestamp, provider, and
counter types. It returns only an identity and an optional related-run refresh
hint. It neither renders upstream strings nor applies event counters to UI state.
The JSON `event_id` is authoritative; do not rely on an SSE transport ID that a hub
may replace. Deliveries can be duplicated or arrive out of order.

`RevisionGate` compares an event against an explicit per-consumer `EventCursor`.
Older or duplicate revisions are ignored. Any newer revision, gap, or different
archive identity requests a fresh authorized API snapshot. A reconnect always
requests a snapshot. Replace the cursor only after a successful snapshot read;
event receipt never advances it. Do not keep user cursors in a shared mutable
service. Job events can also trigger a scoped read of the related run. Run event
counters are hints: the latest snapshot is authoritative for aggregate progress.

For eventual browser wiring, establish the subscription and then fetch the
authoritative snapshot, reconcile any buffered events, and refresh after every
reconnect. The service's initial cursor is an authorization-time baseline, not
a guarantee against changes between its read and connection establishment.
Expired cookies must be renewed through an authenticated application action.
Replacing a cookie replaces the prior grant; combine multiple resource topics
only in a future bounded, independently authorized subscription design.

Live Components can use these services for server-side state refresh. Crawl
publishes JSON; Turbo expects rendered `<turbo-stream>` HTML. Do not attach a Turbo
stream source directly to Crawl's JSON topic or publish arbitrary HTML from a
backend JSON field. Any future Turbo updates must render application-owned Twig
templates after an authorized snapshot read. This PR deliberately adds neither
a renderer nor a component/route that makes that UX choice.

## Validation and references

The unit suite uses mocked HTTP providers and the installed Mercure token factory
to verify scoped reads/submissions, sanitized failures, response bounds, exact
subscriber claims/signatures, credential-free browser configuration, cookie
restrictions, invalid/cross-workspace events, and revision/reconnect handling.

```bash
vendor/bin/phpunit tests/Unit tests/Functional
```

See [Symfony Mercure](https://symfony.com/doc/current/mercure.html),
[Mercure authorization](https://mercure.rocks/docs/concepts/authorization),
[UX Live Components](https://symfony.com/doc/current/ux-live-component/index.html),
and [UX Turbo](https://symfony.com/bundles/ux-turbo/current/index.html).
