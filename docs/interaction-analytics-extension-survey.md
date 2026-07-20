# Interaction analytics extension survey

The official FreshRSS extension catalog has a Reading Time extension, but it
calculates an estimate from article text. It does not measure actual dwell time,
publisher-link activation, or durable per-entry interaction history.

The Recently Read extension exposes entries ordered by FreshRSS’s mutable
`lastUserModified` timestamp. It does not create a telemetry event log.

FreshRSS’s web UI can expose active visibility and link intent. Native clients
using the GReader API can expose read-state transitions, but the API does not
carry visibility duration or publisher-link activation events.
