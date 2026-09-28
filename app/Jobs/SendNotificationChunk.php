<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\NewPostNotification;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * One bulk INSERT for up to chunk_size followers.
 *
 * The notification id is a UUIDv5 of (post, follower), so the primary key is
 * effectively unique on (post_id, user_id) and a retried chunk inserts nothing
 * twice thanks to INSERT IGNORE.
 */
class SendNotificationChunk implements ShouldQueue
{
    use Batchable, Queueable;

    private const string ID_NAMESPACE = '6f1c7a52-8d0e-4b8b-9a54-3f2f7c1e9b10';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * @param  list<int>  $followerIds
     */
    public function __construct(
        public readonly int $postId,
        public readonly int $authorId,
        public readonly string $authorName,
        public readonly array $followerIds,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $notification = new NewPostNotification($this->postId, $this->authorId, $this->authorName);
        $morphClass = (new User)->getMorphClass();
        $now = now();

        $rows = array_map(fn (int $followerId): array => [
            'id' => self::notificationId($this->postId, $followerId),
            'type' => NewPostNotification::class,
            'notifiable_type' => $morphClass,
            'notifiable_id' => $followerId,
            'data' => json_encode($notification->toArray(new User)),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->followerIds);

        DB::table('notifications')->insertOrIgnore($rows);
    }

    public static function notificationId(int $postId, int $followerId): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, "post:{$postId}:user:{$followerId}")->toString();
    }
}
