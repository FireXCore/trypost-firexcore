# UPSTREAM.md

Provenance of this repository. `FORK.md` explains *what* we changed and *why*;
this file records *where it came from* and how to keep that link verifiable.

## Upstream

| Field | Value |
|---|---|
| Repository | https://github.com/trypostit/trypost |
| Tag | `v1.0.9` |
| Released | 2026-09-04 |
| Licence | **AGPL-3.0-only** |
| Language | PHP 8.2+ / Laravel 13 |
| Source obtained from | `https://codeload.github.com/trypostit/trypost/tar.gz/refs/tags/v1.0.9` |
| Tarball sha256 | `66795430bab9c1ca4c37744e2ec6db09922a54674c4aeede1bc4adbd24d434a3` |
| Verified | 2026-09-07 |

## Fork identity

| Field | Value |
|---|---|
| Upstream baseline commit | tagged `v1.0.9-upstream` |
| Our release | tagged `v1.0.9-firexcore` |
| Licence | **AGPL-3.0-only**, unchanged |
| Deployed as | `social.firexcore.com` |

The first commit in this repository is **verbatim upstream v1.0.9**, with no
modifications of any kind. Every change we made is therefore exactly:

```bash
git diff v1.0.9-upstream..v1.0.9-firexcore
```

That property is the point of the two-tag structure: it must stay true through
every future upgrade, so that an auditor — or the AGPL §13 obligation — can be
satisfied by a single diff rather than by trust.

## Attribution

`LICENSE.md` is upstream's AGPL-3.0 text and is **unchanged**. Upstream's
copyright and authorship (Paulo Castellano, TryPost.it) are preserved in
`composer.json`, `README.md` and every file header we did not create.

Nothing in this fork removes, rewrites or obscures upstream attribution, and
nothing may. We added files and made three narrow, additive edits; we did not
rebrand the project.

## AGPL §13 — source availability

This fork is a **modified** version of an AGPL-3.0 program, and it is reachable
over a network. §13 therefore obliges its operator to offer the Corresponding
Source *of the modified version* to everyone who interacts with it. Upstream's
public repository does not discharge that obligation, because it is not what we
are running.

In practice, for the `social.firexcore.com` deployment:

* this repository, at the deployed tag, **is** the Corresponding Source;
* it must be published (or otherwise offered) at a URL reachable by anyone
  interacting with the instance, and that URL set as the engine's source link;
* this is a **deployment gate**, recorded as such in the Digital Pulse runbook
  (`ops/SOCIAL_ENGINE_RUNBOOK.md`), not an optional courtesy.

Digital Pulse is **not** covered by this obligation: it contains no TryPost
code, never links it, never reads its database, and communicates only across an
authenticated network boundary.

## Reviewing and upgrading a future upstream release

Full procedure in `FORK.md` (§ *Updating from upstream*). The short form:

```bash
git remote add upstream https://github.com/trypostit/trypost.git   # once
git fetch upstream --tags

# 1. Read what changed in the files we touch, BEFORE rebasing.
git diff v1.0.9..<new-tag> -- \
  routes/api.php \
  app/Http/Controllers/Api/PostController.php \
  app/Http/Controllers/App/AnalyticsController.php \
  app/Http/Resources/Api/SocialAccountResource.php \
  'app/Services/Social/*Analytics.php' \
  lang/en/analytics.php

# 2. Rebase our additive layer onto the new baseline.
git checkout -b upgrade/<new-tag>
git rebase --onto <new-tag> v1.0.9-upstream

# 3. Re-anchor the baseline tag so the "one diff" property survives.
git tag -f v1.0.9-upstream <new-tag>     # or a new <version>-upstream tag
```

Four things must be checked by hand on every upgrade, because each fails
**silently** rather than loudly:

1. **`lang/en/analytics.php`** — a *new* metric key flows through
   automatically; a *renamed* one silently changes the stable `key` our
   consumer stores. Diff it every time.
2. **A newly supported platform's `getMetrics()`** — add it to
   `AccountAnalyticsResolver::SUPPORTED_PLATFORMS`, or the API reports it
   `supported: false` and Digital Pulse correctly stores nothing for it.
3. **`SocialAccountResource`** — keep `workspace_id`. Digital Pulse fails closed
   without it and will collect nothing rather than collect the wrong thing.
4. **`LoadWorkspaceFromToken`** — if upstream changes how a token binds to a
   workspace, the entire tenancy model changes with it. Read it in full.

Then re-run this repository's API tests **and** the Digital Pulse contract test
(`npm run test:tenancy`), and publish the updated source (§13 above).
