<?php

namespace Tests\Feature\Notifications;

use App\Models\Communication;
use App\Models\User;
use App\Notifications\CommunicationFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 11: every query is scoped to the authenticated user's own
 * notifications relation — proving a user can never read or act on
 * another user's notification, without needing a separate Policy class.
 */
class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
    }

    public function test_a_user_sees_only_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $communication = Communication::factory()->create(['user_id' => $user->id]);
        $user->notify(new CommunicationFailedNotification($communication));
        $other->notify(new CommunicationFailedNotification(Communication::factory()->create(['user_id' => $other->id])));

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertSee($communication->recipient);

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_marking_a_notification_read(): void
    {
        $user = User::factory()->create();
        $communication = Communication::factory()->create(['user_id' => $user->id]);
        $user->notify(new CommunicationFailedNotification($communication));
        $notification = $user->notifications()->firstOrFail();

        $this->actingAs($user)->post("/notifications/{$notification->id}/read")
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_user_cannot_mark_another_users_notification_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $communication = Communication::factory()->create(['user_id' => $other->id]);
        $other->notify(new CommunicationFailedNotification($communication));
        $notification = $other->notifications()->firstOrFail();

        $this->actingAs($user)->post("/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_marking_all_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new CommunicationFailedNotification(Communication::factory()->create(['user_id' => $user->id])));
        $user->notify(new CommunicationFailedNotification(Communication::factory()->create(['user_id' => $user->id])));

        $this->actingAs($user)->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(2, $user->notifications()->count());
    }
}
