<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the admin bell's contract. The panel previously came up empty on every
 * login because the init call returned ids with blank messages purely to mark
 * them seen — so the day's activity was consumed by the request meant to show it.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_init_returns_rendered_activity_instead_of_blank_ids(): void
    {
        $admin = Staff::factory()->create(['role' => 'Administrator']);

        Attendance::create([
            'visitor_name' => 'Ana Reyes',
            'visit_date'   => today()->toDateString(),
            'method'       => 'registered',
        ]);

        $res = $this->actingAs($admin)->getJson(route('notifications.poll', ['init' => 1]));

        $res->assertOk()->assertJsonStructure(['count', 'items', 'pending', 'today_attendance', 'server_time']);

        $items = $res->json('items');
        $this->assertNotEmpty($items, 'today\'s activity should populate the panel on login');
        $this->assertSame('Ana Reyes checked in', $items[0]['message']);
        $this->assertNotSame('', $items[0]['time']);
    }

    public function test_unpaid_and_unverified_visitors_surface_as_pending_actions(): void
    {
        $admin = Staff::factory()->create(['role' => 'Administrator']);

        Visitor::create([
            'first_name' => 'Tomas', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist',
            'admission_fee' => 50, 'payment_status' => 'Unpaid', 'id_verified' => false,
        ]);
        Visitor::create([
            'first_name' => 'Lita', 'last_name' => 'Bautista', 'visitor_type' => 'Local',
            'admission_fee' => 0, 'payment_status' => 'Free', 'id_verified' => false,
        ]);

        $pending = $this->actingAs($admin)
            ->getJson(route('notifications.poll', ['init' => 1]))
            ->json('pending');

        $types = array_column($pending, 'type');
        $this->assertContains('payment', $types, 'an unpaid fee should be flagged');
        $this->assertContains('id_check', $types, 'an unsighted local ID should be flagged');

        foreach ($pending as $p) {
            $this->assertNotEmpty($p['action_url'], 'each pending task needs its POST target for the inline button');
        }
    }

    public function test_every_desk_event_reaches_the_bell(): void
    {
        $admin = Staff::factory()->create(['role' => 'Administrator']);

        // Registered, then paid; a local whose ID was sighted; an anonymous
        // walk-in; a rating left on the way out.
        $tourist = Visitor::create([
            'first_name' => 'Tomas', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist',
            'admission_fee' => 50, 'payment_status' => 'Paid', 'paid_at' => now(), 'id_verified' => false,
        ]);
        Visitor::create([
            'first_name' => 'Lita', 'last_name' => 'Bautista', 'visitor_type' => 'Local',
            'admission_fee' => 0, 'payment_status' => 'Free',
            'id_verified' => true, 'verified_at' => now(), 'verified_by' => 'Rosa',
        ]);
        \App\Models\VisitGroup::create([
            'group_name' => 'Baler NHS', 'group_type' => 'School', 'contact_name' => 'Sir Ben',
            'visitor_type' => 'Local', 'headcount' => 40, 'local_count' => 40, 'paying_count' => 0, 'visit_date' => today(),
            'total_fee' => 0, 'payment_status' => 'Free',
        ]);
        \App\Models\Attendance::create([
            'visitor_id' => null, 'visitor_name' => null, 'visit_date' => today(),
        ]);
        \App\Models\Feedback::create([
            'visitor_id' => $tourist->visitor_id, 'rating' => 4, 'comment' => 'Lovely', 'submitted_at' => now(),
        ]);

        $messages = collect($this->actingAs($admin)
            ->getJson(route('notifications.poll', ['init' => 1]))
            ->json('items'))->pluck('message')->implode(' | ');

        $this->assertStringContainsString('Tomas Cruz registered', $messages);
        $this->assertStringContainsString('Tomas Cruz paid ₱50.00', $messages);
        $this->assertStringContainsString("Lita Bautista's residency ID was verified by Rosa", $messages);
        $this->assertStringContainsString('Baler NHS registered — party of 40', $messages);
        $this->assertStringContainsString('An anonymous visitor checked in', $messages);
        $this->assertStringContainsString('Tomas Cruz left feedback — ★★★★', $messages);
    }

    public function test_dashboard_stat_tiles_link_to_their_sections(): void
    {
        $admin = Staff::factory()->create(['role' => 'Administrator']);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('?tab=visitors', false)
            ->assertSee('?tab=scans', false)
            ->assertSee(route('feedback.index'), false)
            ->assertSee(route('attendance.index'), false);
    }
}
