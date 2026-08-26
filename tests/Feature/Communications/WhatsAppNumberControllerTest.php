<?php

namespace Tests\Feature\Communications;

use App\Models\Team;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 11: WhatsApp business number management is Manager-only.
 */
class WhatsAppNumberControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_register_a_whatsapp_number(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/communications/whatsapp-numbers', [
            'display_name' => 'Sales Line',
            'phone_number' => '+15551234567',
            'phone_number_id' => 'pnid-123',
        ])->assertRedirect(route('communications.whatsapp-numbers.index'));

        $this->assertDatabaseHas('whatsapp_business_numbers', [
            'phone_number_id' => 'pnid-123',
            'created_by' => $manager->id,
        ]);
    }

    public function test_a_team_head_cannot_register_a_whatsapp_number(): void
    {
        $team = Team::factory()->create();
        $teamHead = User::factory()->teamHead($team)->create();

        $this->actingAs($teamHead)->post('/communications/whatsapp-numbers', [
            'display_name' => 'Sales Line',
            'phone_number' => '+15551234567',
            'phone_number_id' => 'pnid-123',
        ])->assertForbidden();

        $this->assertDatabaseCount('whatsapp_business_numbers', 0);
    }

    public function test_a_team_member_cannot_view_the_create_form(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/communications/whatsapp-numbers/create')->assertForbidden();
    }

    public function test_a_manager_can_delete_a_whatsapp_number(): void
    {
        $manager = User::factory()->manager()->create();
        $number = WhatsAppBusinessNumber::factory()->create();

        $this->actingAs($manager)->delete("/communications/whatsapp-numbers/{$number->id}")
            ->assertRedirect(route('communications.whatsapp-numbers.index'));

        $this->assertDatabaseMissing('whatsapp_business_numbers', ['id' => $number->id]);
    }

    public function test_a_non_manager_cannot_delete_a_whatsapp_number(): void
    {
        $member = User::factory()->create();
        $number = WhatsAppBusinessNumber::factory()->create();

        $this->actingAs($member)->delete("/communications/whatsapp-numbers/{$number->id}")->assertForbidden();

        $this->assertDatabaseHas('whatsapp_business_numbers', ['id' => $number->id]);
    }
}
