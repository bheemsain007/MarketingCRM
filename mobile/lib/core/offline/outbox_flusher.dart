import 'dart:async';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../connectivity/connectivity_monitor.dart';
import 'outbox.dart';
import 'outbox_entry.dart';

/// What one drain of the queue did.
class FlushReport {
  const FlushReport({
    this.sent = 0,
    this.alreadyApplied = 0,
    this.failedPermanently = 0,
    this.remaining = 0,
    this.stoppedBecauseOffline = false,
    this.skippedBecauseBusy = false,
  });

  /// Accepted by the server on this attempt.
  final int sent;

  /// The server said it already had this write (409 write-once, or the same
  /// `Idempotency-Key` replayed). Counted separately because it is the number
  /// that proves the queue is not double-sending.
  final int alreadyApplied;

  final int failedPermanently;
  final int remaining;
  final bool stoppedBecauseOffline;

  /// A flush was already running. Not an error — it is the single-flight guard
  /// doing its job.
  final bool skippedBecauseBusy;

  int get total => sent + alreadyApplied + failedPermanently;
}

/// Drains the [Outbox] whenever there is a plausible network.
///
/// The three guarantees, and how each is obtained:
///
///  * **No duplicate sends.** A single-flight `_running` latch means two
///    triggers (connectivity returning while the user also taps "retry") cannot
///    both send entry #1. Each entry carries an `Idempotency-Key` fixed at
///    enqueue time, so even a send that succeeded and then lost the response is
///    safe to repeat. And `PATCH /calls/{id}` is write-once server-side, so a
///    genuine second delivery comes back `409` — which this class treats as
///    **success**, because it means the outcome is recorded.
///  * **Nothing is lost.** An entry leaves the queue only on a terminal server
///    answer. A transport failure puts it back and stops the drain.
///  * **Order is kept.** Strictly FIFO, and the drain halts at the first entry
///    that could not be delivered rather than skipping past it.
class OutboxFlusher {
  OutboxFlusher({
    required Outbox outbox,
    required ApiClient api,
    required ConnectivityMonitor connectivity,
  })  : _outbox = outbox,
        _api = api,
        _connectivity = connectivity;

  final Outbox _outbox;
  final ApiClient _api;
  final ConnectivityMonitor _connectivity;

  StreamSubscription<bool>? _subscription;
  bool _running = false;

  /// Starts flushing on every connectivity restoration.
  void start() {
    _subscription ??= _connectivity.onChanged.listen((online) {
      if (online) {
        unawaited(flush());
      }
    });
  }

  Future<void> dispose() async {
    await _subscription?.cancel();
    _subscription = null;
  }

  /// Sends everything it can, oldest first.
  Future<FlushReport> flush() async {
    if (_running) {
      return const FlushReport(skippedBecauseBusy: true);
    }

    _running = true;
    try {
      await _outbox.load();

      var sent = 0;
      var alreadyApplied = 0;
      var failed = 0;

      // Snapshot: the live list mutates as entries are removed.
      for (final entry in _outbox.entries) {
        if (entry.permanentlyFailed) {
          continue;
        }

        final outcome = await _deliver(entry);

        switch (outcome) {
          case _DeliveryOutcome.accepted:
            sent++;
          case _DeliveryOutcome.alreadyApplied:
            alreadyApplied++;
          case _DeliveryOutcome.rejected:
            failed++;
          case _DeliveryOutcome.deferred:
            // Offline again, or the token expired. Stop: everything behind this
            // entry would fail the same way, and burning through the queue
            // would only inflate the attempt counters.
            return FlushReport(
              sent: sent,
              alreadyApplied: alreadyApplied,
              failedPermanently: failed,
              remaining: _outbox.pendingCount,
              stoppedBecauseOffline: true,
            );
        }
      }

      return FlushReport(
        sent: sent,
        alreadyApplied: alreadyApplied,
        failedPermanently: failed,
        remaining: _outbox.pendingCount,
      );
    } finally {
      _running = false;
    }
  }

  Future<_DeliveryOutcome> _deliver(OutboxEntry entry) async {
    try {
      await _api.send(
        entry.method,
        entry.path,
        body: entry.body,
        idempotencyKey: entry.idempotencyKey,
      );

      await _outbox.remove(entry.id);

      return _DeliveryOutcome.accepted;
    } on ConflictException {
      // Write-once: the outcome is already on the record. Our earlier attempt
      // landed and we never saw the reply. Removing it here is what stops the
      // queue re-sending forever.
      await _outbox.remove(entry.id);

      return _DeliveryOutcome.alreadyApplied;
    } on UnauthenticatedException {
      await _outbox.replace(entry.copyWith(
        attempts: entry.attempts + 1,
        lastError: 'Waiting for sign-in.',
      ));

      return _DeliveryOutcome.deferred;
    } on ApiException catch (error) {
      if (error.isRetryable) {
        await _outbox.replace(entry.copyWith(
          attempts: entry.attempts + 1,
          lastError: error.message,
        ));

        return _DeliveryOutcome.deferred;
      }

      // 422 / 403 / 404 — retrying sends the identical request and gets the
      // identical refusal. Kept and flagged so a human decides.
      await _outbox.replace(entry.copyWith(
        attempts: entry.attempts + 1,
        lastError: error.message,
        permanentlyFailed: true,
      ));

      return _DeliveryOutcome.rejected;
    }
  }
}

enum _DeliveryOutcome { accepted, alreadyApplied, rejected, deferred }
