<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Database notification telling a follower that someone they follow posted.
 *
 * Rows are bulk-inserted by SendNotificationChunk rather than sent one by one
 * through Notification::send(), but they keep the standard shape so
 * $user->notifications works as usual.
 */
class NewPostNotification extends Notification
{
    public function __construct(
        public readonly int $postId,
        public readonly int $authorId,
        public readonly string $authorName,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{post_id: int, author: array{id: int, name: string}}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'post_id' => $this->postId,
            'author' => ['id' => $this->authorId, 'name' => $this->authorName],
        ];
    }
}
