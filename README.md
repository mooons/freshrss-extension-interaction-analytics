# FreshRSS Interaction Analytics

This standalone user extension records optional reading interactions for a FreshRSS account.

## Features

- Active visible time for unread entries in the FreshRSS web UI.
- Publisher-link activation state.
- Read-state-only records from GReader clients such as Reeder Classic.
- Per-feed opt-in tracking.
- Compact per-entry badges and feed summaries in the web UI.
- Grouped JSON export and explicit deletion.
- Optional historical title/GUID/link/feed snapshots, disabled by default.

The new Reeder application does not support third-party sync services and cannot send these signals. Reeder Classic can synchronize read state through FreshRSS’s GReader API, but it cannot provide dwell time or external-link activation to this extension.

## Installation

Copy or symlink this directory into the FreshRSS `extensions/` directory, then enable **Interaction Analytics** for the desired user. For Docker development:

```sh
make start extensions="/absolute/path/to/freshrss-extension-interaction-analytics"
```

The extension creates its table in the current user’s FreshRSS database. It does not require a separate service or database.

## Privacy and storage

Tracking is disabled by default. The optional historical-metadata setting stores small feed and entry metadata snapshots so exports remain readable after FreshRSS purges articles; it does not store article content. Existing snapshots are retained when the setting is turned off and are removed only by explicit telemetry deletion.

Rows are kept when the extension is disabled or uninstalled. Use the configuration page to export or delete selected/all data.
