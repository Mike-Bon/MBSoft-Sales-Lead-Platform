<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PeriodPreset;
use App\Models\User;
use App\Support\PeriodSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PeriodSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_month_resolves_correctly(): void
    {
        $selection = PeriodSelection::fromRequest(Request::create('/dashboard', 'GET', ['period' => 'current_month']));

        $this->assertSame(PeriodPreset::CurrentMonth, $selection->preset);
        $this->assertTrue($selection->start->isSameDay(Carbon::now()->startOfMonth()));
        $this->assertTrue($selection->end->isSameDay(Carbon::now()->endOfMonth()));
    }

    public function test_previous_month_resolves_correctly(): void
    {
        $selection = PeriodSelection::fromRequest(Request::create('/dashboard', 'GET', ['period' => 'previous_month']));

        $expectedStart = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $this->assertTrue($selection->start->isSameDay($expectedStart));
        $this->assertTrue($selection->start->lt(Carbon::now()->startOfMonth()));
    }

    public function test_current_quarter_resolves_correctly(): void
    {
        $selection = PeriodSelection::fromRequest(Request::create('/dashboard', 'GET', ['period' => 'current_quarter']));

        $this->assertTrue($selection->start->isSameDay(Carbon::now()->startOfQuarter()));
        $this->assertTrue($selection->end->isSameDay(Carbon::now()->endOfQuarter()));
    }

    public function test_previous_quarter_resolves_correctly(): void
    {
        $selection = PeriodSelection::fromRequest(Request::create('/dashboard', 'GET', ['period' => 'previous_quarter']));

        $this->assertTrue($selection->start->lt(Carbon::now()->startOfQuarter()));
    }

    public function test_current_year_resolves_correctly(): void
    {
        $selection = PeriodSelection::fromRequest(Request::create('/dashboard', 'GET', ['period' => 'current_year']));

        $this->assertTrue($selection->start->isSameDay(Carbon::now()->startOfYear()));
        $this->assertTrue($selection->end->isSameDay(Carbon::now()->endOfYear()));
    }

    public function test_custom_period_resolves_correctly(): void
    {
        $selection = PeriodSelection::fromRequest(Request::create('/dashboard', 'GET', [
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-15',
        ]));

        $this->assertSame(PeriodPreset::Custom, $selection->preset);
        $this->assertSame('2026-02-01', $selection->start->toDateString());
        $this->assertSame('2026-02-15', $selection->end->toDateString());
    }

    public function test_defaults_to_current_month_when_nothing_supplied(): void
    {
        $selection = PeriodSelection::fromRequest(Request::create('/dashboard', 'GET'));

        $this->assertSame(PeriodPreset::CurrentMonth, $selection->preset);
    }

    public function test_dashboard_accepts_every_preset_without_error(): void
    {
        $manager = User::factory()->manager()->create();

        foreach (PeriodPreset::selectable() as $preset) {
            $this->actingAs($manager)->get('/dashboard?period='.$preset->value)->assertOk();
        }
    }

    public function test_dashboard_accepts_a_custom_period(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->get('/dashboard?period_start=2026-01-01&period_end=2026-01-31')
            ->assertOk();
    }
}
