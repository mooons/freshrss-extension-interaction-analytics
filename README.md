# FreshRSS Interaction Analytics

This standalone user extension records optional reading interactions for a FreshRSS account.

> [!WARNING]
> **FreshRSS compatibility:** GReader/Reeder Classic read-state ingestion is
> optional and disabled by default. It requires the `Minz_HookType::EntriesRead`
> hook, which is not present in the latest stable FreshRSS release (`1.29.1`).
> The setting is shown disabled with an explanation on versions without that
> hook. It was added by [FreshRSS commit
> `310bcb5e902e5dea757fd8ea86c6d3e5cb87a19d`](https://github.com/FreshRSS/FreshRSS/commit/310bcb5e902e5dea757fd8ea86c6d3e5cb87a19d);
> enable the setting only after that change is included in a released FreshRSS
> version. Browser-side telemetry does not require this hook: it uses
> `freshrss:entryStateChange` when available and observes the confirmed
> `not_read` class transition as a fallback on FreshRSS `1.29.1`.

## Features

- Active visible time for tracked entries in the FreshRSS web UI. Timing starts
  while an unread entry is active and ends when the user leaves the entry or
  switches away from the browser; automatic marking-as-read does not stop the
  timer.
- Publisher-link activation state.
- Optional read-state-only records from GReader clients such as Reeder Classic
  when FreshRSS provides the `EntriesRead` hook.
- Per-feed opt-in tracking.
- Compact per-entry badges and feed summaries in the web UI.
- Grouped JSON export and explicit deletion.
- Optional historical title/GUID/link/feed snapshots, disabled by default.
- Optional per-user server-side title model, disabled by default. Training runs
  during FreshRSS user maintenance and does not require exporting telemetry or
  running a separate service.
- Batched `0–100` interest-score badges with title/feed explanations.
- **More like this**, **Less like this**, and **Clear feedback** controls.

The new Reeder application does not support third-party sync services and cannot send these signals. Reeder Classic can synchronize read state through FreshRSS’s GReader API, but it cannot provide dwell time or external-link activation to this extension.

## Design documents

- [Server-side interest model design](docs/server-side-interest-model-design.md)
- [Interaction analytics extension survey](docs/interaction-analytics-extension-survey.md)

## Installation

Copy or symlink this directory into the FreshRSS `extensions/` directory, then enable **Interaction Analytics** for the desired user. GReader/Reeder Classic ingestion is separately opt-in in the extension settings and remains unavailable until FreshRSS provides `EntriesRead`. For Docker development:

```sh
make start extensions="/absolute/path/to/freshrss-extension-interaction-analytics"
```

The extension creates its telemetry, model-state, and score-cache tables in the
current user’s FreshRSS database. It does not require a separate service,
database, Python runtime, browser-side training, or network access.

Already-enabled installations are upgraded automatically on the first request
after installing version `0.4.0`. A failed model-schema migration is isolated:
the interest model is shown unavailable while existing telemetry remains
operational.

## Server-side interest model

The interest model is separately opt-in. It learns only from currently selected
tracked feeds and uses title word/phrase features, CJK character n-grams, and
feed identity. Article content and interaction outcomes are never scoring
features.

Implicit training examples settle for one hour. Explicit feedback and current
favorites are eligible immediately; feedback overrides other signals,
publisher-link opens are positive signals, and a measured session of at most
two seconds without a publisher-link open is a weak negative.
Read-state-only GReader records remain ambiguous unless a favorite or explicit
feedback supplies a label. At least 20 positive and 50 negative examples are
required.

Automatic training runs only from FreshRSS’s per-user maintenance hook and is
bounded to 20,000 examples, 5,000 retained features, five seconds, 128 MiB of
additional memory, and a 2 MiB model artifact. **Request training** schedules
work for the next maintenance run; it never trains inside the settings request.

Version `0.4.0` is score-only. Automatic collapsing is intentionally deferred
until a later release and will require at least 50 validation examples, 20
predicted-low examples, 95% low-interest precision, and no strong-positive
false negatives.

## Privacy and storage

Tracking and model training are disabled by default. The optional
historical-metadata setting stores small feed and entry metadata snapshots so
exports and later retraining remain useful after FreshRSS purges articles; it
does not store article content. Existing snapshots are retained when the setting
is turned off and are removed only by explicit telemetry deletion.

Rows, model weights, and cached scores are kept when the extension is disabled
or uninstalled. Use the configuration page to export telemetry, delete selected
or all source data, or delete only the model and cached scores. Deleting source
telemetry also invalidates derived model data; model weights are not included in
normal telemetry exports.
