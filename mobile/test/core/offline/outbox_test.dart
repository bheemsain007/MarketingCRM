import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/offline/outbox.dart';
import 'package:marketing_crm_mobile/core/offline/outbox_entry.dart';
import 'package:marketing_crm_mobile/core/storage/key_value_store.dart';

OutboxEntry entry(String id, {String label = 'Outcome', bool failed = false}) {
  return OutboxEntry(
    id: id,
    kind: OutboxKind.callOutcome,
    method: 'PATCH',
    path: '/calls/$id',
    body: <String, dynamic>{'status': 'connected', 'duration_seconds': 90},
    idempotencyKey: id,
    queuedAt: DateTime.utc(2026, 8, 12, 11),
    label: label,
    permanentlyFailed: failed,
  );
}

void main() {
  group('Outbox', () {
    test('survives a restart', () async {
      final store = InMemoryKeyValueStore();

      final first = Outbox(store);
      await first.load();
      await first.add(entry('900', label: 'Call outcome for Ramesh Kumar'));

      // A brand new Outbox over the same storage is what the next launch sees.
      final second = Outbox(store);
      await second.load();

      expect(second.entries, hasLength(1));
      expect(second.entries.single.id, '900');
      expect(second.entries.single.label, 'Call outcome for Ramesh Kumar');
      expect(second.entries.single.body['duration_seconds'], 90);
      expect(second.entries.single.idempotencyKey, '900');
    });

    test('keeps insertion order — outcomes are evidence and arrive in sequence', () async {
      final outbox = Outbox(InMemoryKeyValueStore());

      await outbox.add(entry('1'));
      await outbox.add(entry('2'));
      await outbox.add(entry('3'));

      expect(outbox.entries.map((e) => e.id).toList(), <String>['1', '2', '3']);
    });

    test('counts pending and permanently-failed separately', () async {
      final outbox = Outbox(InMemoryKeyValueStore());

      await outbox.add(entry('1'));
      await outbox.add(entry('2', failed: true));

      expect(outbox.pendingCount, 1);
      expect(outbox.failedCount, 1);
    });

    test('notifies listeners so the banner cannot go stale', () async {
      final outbox = Outbox(InMemoryKeyValueStore());
      var notifications = 0;
      outbox.addListener(() => notifications++);

      await outbox.add(entry('1'));
      await outbox.remove('1');

      expect(notifications, 2);
      expect(outbox.isEmpty, isTrue);
    });

    test('removing an id that is not there changes nothing', () async {
      final outbox = Outbox(InMemoryKeyValueStore());
      await outbox.add(entry('1'));

      await outbox.remove('nope');

      expect(outbox.entries, hasLength(1));
    });

    test('a corrupt queue file is dropped rather than bricking the launch', () async {
      final store = InMemoryKeyValueStore(<String, String>{Outbox.storageKey: 'not json at all'});
      final outbox = Outbox(store);

      await outbox.load();

      expect(outbox.entries, isEmpty);
      expect(await store.read(Outbox.storageKey), isNull);
    });

    test('load is idempotent — a second call cannot wipe newer entries', () async {
      final outbox = Outbox(InMemoryKeyValueStore());
      await outbox.load();
      await outbox.add(entry('1'));

      await outbox.load();

      expect(outbox.entries, hasLength(1));
    });

    test('newId is unique across rapid consecutive calls', () {
      final ids = List<String>.generate(500, (_) => OutboxEntry.newId());

      expect(ids.toSet(), hasLength(500));
    });
  });
}
