# Changelog

All notable changes to `cinderwatch/cinder-laravel` are documented here.

## [1.0.0] - 2026-11-27

First stable release.

### Added

- Exception reporting through `Cinder::reportExceptions()` in `bootstrap/app.php`, with request and user context.
- The `Cinder` facade: `report()`, `reportOnlyWhen()`, `fingerprintUsing()`, `beforeSend()`, `addEnricher()`, `trace()` for custom spans, `log()`, `registerProbe()`, `probes()` and `flush()`.
- A `cinder` log channel.
- The deployed commit is detected from the `.git` folder or the host's variables, with `CINDER_VCS_*` as overrides.
- Performance tracing for web and API requests, queued jobs, Artisan commands, database queries, rendered views, Livewire components and outgoing HTTP (the `Http` facade, Saloon and a Guzzle middleware), with sampling and ignore lists.
- A health endpoint with database, cache, queue, debug mode, environment, optimisation, scheduler heartbeat and Horizon probes, and snapshots shared across servers.
- Schedule monitoring with started, succeeded and failed heartbeats for every scheduled task.
- The `cinder:test`, `cinder:health` and `cinder:schedule-sync` commands.
