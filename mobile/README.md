# Marketing CRM — Android client

The mobile half of the CRM (Phase 30). Flutter, Android-first.

## What it does

- **Sign in** against the same API the Web CRM uses, with a bearer token held in
  Android Keystore-backed secure storage. Signing in opens a work session, so
  attendance reporting counts app users too (FR-ATT-01).
- **Leads** — list with search and paging, and a detail screen with call history.
- **Calling** — the app does **not** place the call. It opens the native dialer
  and then records the outcome (ADR-B). Before offering to dial it asks the
  server whether the lead may be called at all, and refuses with the reason the
  server gives.
- **Follow-ups** — the signed-in user's own, and completing one.
- **Offline** — a call outcome written with no network is queued and flushed
  when connectivity returns, keyed so a retry cannot send it twice.

## What it deliberately does not do

**No business rule is implemented here.** The DNC gate, calling hours, status
transitions and scoring all live on the server; the app renders what it is told.
A client that re-derived any of them would be a second implementation free to
drift from the first.

**No call recording.** Phases 31/32 depend on T-44 — whether Android still
permits third-party call recording on real handsets — which cannot be answered
without a physical device.

## Running it

The backend URL is a build-time `--dart-define`, not an in-app setting: a
telecaller must not be able to point a client holding real lead data at an
arbitrary host.

```bash
flutter pub get

# Android emulator — 10.0.2.2 is the host machine as seen from the emulator,
# and is the default if you pass nothing.
flutter run --dart-define=CRM_BASE_URL=http://10.0.2.2:8000

# A real device on the same network, or a deployed backend
flutter run --dart-define=CRM_BASE_URL=https://crm.example.com
```

The backend must be reachable at that host with `/api/v1` beneath it — the path
prefix belongs to the client, so do not include it in the URL.

## Tests

```bash
flutter analyze     # expected: no issues
flutter test        # expected: 87 passing
```

Tests drive the **real** `ApiClient` against a scripted backend rather than a
mocked repository, so the response envelope, header building and
status-to-exception mapping are genuinely exercised. A test that mocked one
layer higher would pass while the envelope contract was broken.

> Widget tests must not call `Harness.dispose()`. It tears down the dependency
> graph while the test framework still owns the widget tree's lifecycle, and the
> test then hangs rather than failing — see the note on that method.

## Layout

```
lib/
  core/         api client + envelope, offline outbox, connectivity, storage, dialer
  models/       API shapes
  repositories/ one per API area
  state/        controllers (ChangeNotifier)
  screens/      login, home shell, leads, lead detail, follow-ups
```
