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

- Active visible time for unread entries in the FreshRSS web UI.
- Publisher-link activation state.
- Optional read-state-only records from GReader clients such as Reeder Classic
  when FreshRSS provides the `EntriesRead` hook.
- Per-feed opt-in tracking.
- Compact per-entry badges and feed summaries in the web UI.
- Grouped JSON export and explicit deletion.
- Optional historical title/GUID/link/feed snapshots, disabled by default.

The new Reeder application does not support third-party sync services and cannot send these signals. Reeder Classic can synchronize read state through FreshRSS’s GReader API, but it cannot provide dwell time or external-link activation to this extension.

## Installation

Copy or symlink this directory into the FreshRSS `extensions/` directory, then enable **Interaction Analytics** for the desired user. GReader/Reeder Classic ingestion is separately opt-in in the extension settings and remains unavailable until FreshRSS provides `EntriesRead`. For Docker development:

```sh
make start extensions="/absolute/path/to/freshrss-extension-interaction-analytics"
```

The extension creates its table in the current user’s FreshRSS database. It does not require a separate service or database.

## Privacy and storage

Tracking is disabled by default. The optional historical-metadata setting stores small feed and entry metadata snapshots so exports remain readable after FreshRSS purges articles; it does not store article content. Existing snapshots are retained when the setting is turned off and are removed only by explicit telemetry deletion.

Rows are kept when the extension is disabled or uninstalled. Use the configuration page to export or delete selected/all data.
