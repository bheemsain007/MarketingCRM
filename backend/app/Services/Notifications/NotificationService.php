<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Internal notifications for CRM users (FR-NOTIF-01..03, BR-NOTIF-01/03).
 *
 * **Never DNC-filtered.** `DncService` protects leads from being contacted;
 * these target staff. Routing a colleague's "lead assigned to you" alert
 * through the suppression gate would be a category error - and one that fails
 * silently, because the alert simply would not arrive.
 *
 * In-app is written first and unconditionally (BR-NOTIF-03). Push and email are
 * additional per preference and may fail; the in-app record must survive
 * regardless, because it is the one channel with no external dependency.
 */
class NotificationService
{
    /**
     * Writes one in-app notification.
     *
     * @param  array<string, mixed>  $options  body, action_url, reference
     */
    public function notify(User $user, string $type, string $title, array $options = []): Notification
    {
        /** @var Model|null $reference */
        $reference = $options['reference'] ?? null;

        return $user->crmNotifications()->create([
            'tenant_id' => config('crm.default_tenant_id'),
            'type' => $type,
            'title' => $title,
            'body' => $options['body'] ?? null,
            'channel' => 'in_app',
            'reference_type' => $reference !== null ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'action_url' => $options['action_url'] ?? null,
            // In-app needs no delivery attempt - writing the row IS the
            // delivery, which is why it cannot fail the way a push can.
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Writes one notification per user, skipping nobody silently.
     *
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $options
     * @return int how many were written
     */
    public function notifyMany(iterable $users, string $type, string $title, array $options = []): int
    {
        $count = 0;

        foreach ($users as $user) {
            $this->notify($user, $type, $title, $options);
            $count++;
        }

        return $count;
    }

    /**
     * Marks one notification read, but only for its owner.
     *
     * The ownership check lives here rather than only in the controller: a
     * notification is the most trivially enumerable resource in the system
     * (sequential ids, one per user), so the guard belongs where every caller
     * gets it (SEC-AUTHZ-04).
     */
    public function markRead(Notification $notification, User $user): bool
    {
        if ($notification->user_id !== $user->getKey()) {
            return false;
        }

        // Idempotent: re-reading must not move the timestamp, or "when did they
        // first see this?" becomes unanswerable.
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return true;
    }

    public function markAllRead(User $user): int
    {
        return $user->crmNotifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return $user->crmNotifications()->whereNull('read_at')->count();
    }
}
