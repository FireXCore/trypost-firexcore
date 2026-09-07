# TryPost fork — Firexcore

This repository is a fork of [trypostit/trypost](https://github.com/trypostit/trypost)
pinned to **v1.0.9**, operated as the self-hosted social engine behind Digital
Pulse at `social.firexcore.com`.

| Field | Value |
|---|---|
| Upstream | https://github.com/trypostit/trypost |
| Tag | `v1.0.9` (published 2026-09-04) |
| Upstream baseline commit | tagged here as `v1.0.9-upstream` |
| Source tarball sha256 | `66795430bab9c1ca4c37744e2ec6db09922a54674c4aeede1bc4adbd24d434a3` |
| Upstream licence | **AGPL-3.0-only** |
| This fork's licence | **AGPL-3.0-only** (unchanged — see [Licence](#licence)) |

`git diff v1.0.9-upstream..HEAD` is always the complete set of our changes.

---

## Licence

TryPost is licensed under the GNU Affero General Public License v3.0. This fork
is a modified version of that program and is distributed under the same licence.
`LICENSE.md` is upstream's and is unchanged.

**AGPL §13 (remote network interaction) applies to this deployment.** The engine
is reachable over a network, so its operator must offer users interacting with
it the Corresponding Source of the *modified* version they are interacting with —
not merely upstream's.

What that means in practice for this deployment:

* This repository, at the deployed commit, **is** the Corresponding Source.
* The deployment runbook requires publishing it (or otherwise offering it) at a
  URL reachable by anyone who interacts with `social.firexcore.com`, and setting
  that URL in the engine's footer/source link.
* If you modify this fork further, the obligation follows the modification.

**Digital Pulse itself is NOT a derivative work of TryPost and is not affected
by the AGPL.** It contains no TryPost source. It communicates with this engine
exclusively over an authenticated HTTP API across a process and network
boundary. Calling a separately operated network service does not create a
combined work; copying its code, linking it, or reading its database would.
That boundary is deliberate and is enforced in the Digital Pulse codebase:

* no TryPost code is vendored into Digital Pulse,
* Digital Pulse never connects to the engine's database,
* Digital Pulse never scrapes the engine's UI.

---

## Why this fork exists

Digital Pulse reports social performance per site. Two capabilities it needs are
present in upstream v1.0.9 but not reachable from the REST API. Both additions
are **read-only**, **authenticated**, and **workspace-scoped**.

### 1. Account-level analytics were not exposed over REST

Upstream serves account analytics only to its own web UI
(`App\Http\Controllers\App\AnalyticsController`). The REST API exposed post
metrics but nothing about followers, reach or impressions, so an external
reporting tool could see what was published but never how it performed.

The per-platform services (`getMetrics(SocialAccount, $since, $until)`) already
existed for all ten supported platforms. The fork exposes them, and does not
reimplement them.

**Added:** `GET /api/social-accounts/{account}/analytics?since&until`

```json
{
  "account_id": "…", "platform": "x",
  "supported": true, "reason": null,
  "since": "2026-09-01T00:00:00+00:00", "until": "2026-09-07T23:59:59+00:00",
  "metrics": [ { "key": "impressions", "label": "Impressions", "value": 1200 } ]
}
```

Three design points, each of which exists to stop a wrong number reaching a
dashboard:

* **`key` is a stable machine identifier, `label` is for humans.** The platform
  services label metrics through `__('analytics.metrics.*')`, so the label
  changes with the instance's locale. A consumer keying on `"Impressions"` would
  silently collect nothing the moment someone switched the UI to French.
  `App\Support\Analytics\MetricKey` resolves a rendered label back to its
  catalogue key.
* **`key` is `null` when the metric is not in the catalogue** (a raw platform
  metric name, a Telegram reaction emoji). It stays null. Guessing a key from an
  unrecognised label is how one platform's "Page views" becomes another's
  "Views".
* **`supported: false` is distinct from an empty metric list.** A platform with
  no analytics implementation has told us *nothing*; a platform that ran and
  found nothing has told us *something*. Collapsing the two would record a real
  zero for a figure nobody measured.

The reverse map is built across **every shipped locale**, not just English. The
platform services cache their results under a key that does **not** include the
locale, so a label rendered for a French web session is served verbatim to the
next API read within the TTL. Resolving only English would turn that into total
silent data loss for the consumer.

### 2. `GET /api/posts` had no date window

Upstream paginates the workspace's entire history 15 at a time with no filter. A
reporting client that wants one week had to page through everything and discard
almost all of it — a cost that grows with the account's age rather than with the
window asked for.

**Added:** optional `from`, `to`, `status`, `per_page` (max 100). With no
parameters the behaviour is byte-for-byte what it was.

The window applies to `COALESCE(published_at, scheduled_at)`: a published post's
`scheduled_at` is the plan, `published_at` is the fact. Filtering on
`scheduled_at` alone files a post scheduled on Friday and published on Monday in
the wrong week. Bounds are inclusive whole days in the application timezone,
which this application fixes to UTC.

### 3. `workspace_id` on the social-accounts resource

`SocialAccountResource` now includes `workspace_id`.

This is the smallest possible change and it closes a real hole. Digital Pulse
validates every post's attribution against the set of accounts it believes
belong to the mapped workspace. If that set were taken on trust from a scoped
endpoint, an engine build that over-returned would not merely add stray
channels — it would *license another brand's posts into the wrong site*. With
`workspace_id` present, the consumer verifies tenancy itself and drops anything
it cannot prove.

This was found by an integration test, not by review: the Digital Pulse tenancy
suite has a case that simulates an engine ignoring its own workspace scoping,
and it failed until this field existed.

---

## Changed files

| File | Change |
|---|---|
| `app/Support/Analytics/MetricKey.php` | **new** — locale-independent metric keys |
| `app/Services/Social/AccountAnalyticsResolver.php` | **new** — shared per-platform dispatch, keyed variant for the API |
| `app/Http/Controllers/Api/AccountAnalyticsController.php` | **new** — the read-only endpoint |
| `app/Http/Controllers/App/AnalyticsController.php` | refactored onto the shared resolver (no behaviour change) |
| `app/Http/Controllers/Api/PostController.php` | `index()` gains `from`/`to`/`status`/`per_page` |
| `app/Http/Resources/Api/SocialAccountResource.php` | exposes `workspace_id` |
| `routes/api.php` | registers the analytics route (throttle `60,1`) |
| `tests/Feature/Api/AccountAnalyticsApiTest.php` | **new** |
| `tests/Feature/Api/PostApiWindowTest.php` | **new** |
| `tests/Feature/Api/SocialAccountApiTest.php` | asserts `workspace_id` |

No migration is added by this fork. No upstream table, job, queue, scheduler
entry or publishing path is modified.

### Why the web controller was refactored rather than duplicated

The per-platform `match` for account analytics lived inline in the web
controller. The API needs exactly the same dispatch, and a second copy would
drift: a platform added to one list and not the other reads as "this account has
no analytics" rather than as the omission it is. The web controller's observable
behaviour is unchanged, including the existing guarantee (covered by
`tests/Feature/AnalyticsResilienceTest.php`) that a *bug* in a metrics service
surfaces as a 500 while a *platform outage* degrades to empty.

---

## Tenancy

Every added route sits behind the existing
`['auth:api', 'workspace.token', 'throttle:api']` stack.

`LoadWorkspaceFromToken` resolves the workspace from the **access token**, not
from the user's workspace switcher, so a token is bound to one workspace at
issue time. The analytics controller re-checks the account against that
workspace anyway and returns **404** on a mismatch, because route-model binding
resolves by primary key across every workspace on the instance — without the
check, a token scoped to workspace A would read workspace B's analytics by id.
404 rather than 403: the existence of another tenant's account id is itself
information.

---

## Running the tests

```bash
composer install
php artisan test --filter=AccountAnalyticsApiTest
php artisan test --filter=PostApiWindowTest
php artisan test --filter=SocialAccountApiTest
php artisan test tests/Feature/Api
```

`phpunit.xml` expects a PostgreSQL database named `trypost_test`.

---

## Updating from upstream

The fork is a thin, additive layer, so a rebase is the right model.

```bash
git remote add upstream https://github.com/trypostit/trypost.git   # once
git fetch upstream --tags

# Review what changed in the files we touch before rebasing.
git diff v1.0.9..v1.1.0 -- \
  routes/api.php \
  app/Http/Controllers/Api/PostController.php \
  app/Http/Controllers/App/AnalyticsController.php \
  app/Http/Resources/Api/SocialAccountResource.php \
  'app/Services/Social/*Analytics.php' \
  lang/en/analytics.php

git checkout -b upgrade/v1.1.0
git rebase --onto v1.1.0 v1.0.9-upstream
git tag -f v1.1.0-upstream v1.1.0
```

Then re-run the tests above **and** the Digital Pulse contract test
(`npm run test:tenancy` in the Digital Pulse repo), and check these specifically:

1. **`lang/en/analytics.php`** — a new metric key is fine and flows through
   automatically. A *renamed* key silently changes the `key` field consumers
   store. Diff it every time.
2. **A new platform's `getMetrics()`** — add it to
   `AccountAnalyticsResolver::SUPPORTED_PLATFORMS`, or the API will report it
   `supported: false` and Digital Pulse will correctly store nothing for it.
3. **`SocialAccountResource`** — if upstream restructures it, keep
   `workspace_id`. Digital Pulse fails closed without it and will collect
   nothing rather than collect the wrong thing.
4. **`LoadWorkspaceFromToken`** — if upstream changes how a token binds to a
   workspace, the whole tenancy model here changes with it. Read it in full.

Upstream's own `AnalyticsController` is the one file likely to conflict, because
we refactored it. The conflict is small and mechanical: keep upstream's changes
to `index()`, and keep our `metricsFor()` delegation.
