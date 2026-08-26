<?php

namespace Tests\Feature\Communications;

use App\Enums\ActivityType;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\RecordStatus;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\EmailAccount;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Model-layer sanity checks for the four Phase 6 tables: casts, PHP-side
 * defaults, mass-assignment guarding, and the Activity<->Communication
 * link. Provider/sending behaviour is covered separately (mocked/faked)
 * once CommunicationService/SendCommunicationJob exist.
 */
class CommunicationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_communication_defaults_to_queued_status(): void
    {
        $communication = Communication::factory()->create();

        $this->assertSame(CommunicationStatus::Queued, $communication->status);
        $this->assertSame(CommunicationChannel::Email, $communication->channel);
        $this->assertSame(CommunicationDirection::Outbound, $communication->direction);
    }

    public function test_communication_fillable_is_empty_so_ownership_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $this->expectException(MassAssignmentException::class);

        (new Communication)->fill([
            'user_id' => $user->id,
            'channel' => CommunicationChannel::Email->value,
            'recipient' => 'attacker-controlled@example.test',
        ]);
    }

    public function test_email_account_tokens_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'super-secret-access-token',
        ]);

        $raw = \DB::table('email_accounts')->where('id', $account->id)->value('access_token');
        $this->assertNotSame('super-secret-access-token', $raw);
        $this->assertStringNotContainsString('super-secret-access-token', (string) $raw);

        $this->assertSame('super-secret-access-token', $account->fresh()->access_token);
        $this->assertArrayNotHasKey('access_token', $account->toArray());
        $this->assertArrayNotHasKey('refresh_token', $account->toArray());
        $this->assertSame(EmailAccountStatus::Connected, $account->status);
    }

    public function test_email_account_is_unique_per_user(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);
        EmailAccount::factory()->create(['user_id' => $user->id]);
    }

    public function test_whatsapp_business_number_relationships_resolve(): void
    {
        $creator = User::factory()->create();
        $number = WhatsAppBusinessNumber::factory()->create(['created_by' => $creator->id]);

        $this->assertTrue($number->createdBy->is($creator));
        $this->assertNull($number->team);
    }

    public function test_message_template_defaults_to_active(): void
    {
        $template = MessageTemplate::factory()->create();

        $this->assertSame(RecordStatus::Active, $template->status);
    }

    public function test_communication_links_to_its_activity_and_vice_versa(): void
    {
        $user = User::factory()->create();
        $communication = Communication::factory()->create(['user_id' => $user->id]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'type' => ActivityType::Email,
        ]);
        $activity->communication_id = $communication->id;
        $activity->save();

        $this->assertTrue($communication->fresh()->activity->is($activity));
        $this->assertTrue($activity->fresh()->communication->is($communication));
    }
}
