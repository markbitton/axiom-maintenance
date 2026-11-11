<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\User;
use App\Models\WorkOrder;

class AccessTest extends MaintenanceTestCase
{
    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/queue')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_each_role_lands_on_its_home(): void
    {
        $this->actingAs($this->staff('admin'))->get('/')->assertRedirect('/admin');
        $this->actingAs($this->staff('scheduler'))->get('/')->assertRedirect('/queue');
        $this->actingAs($this->tech('Mike', ['plumbing' => 3])->user)->get('/')->assertRedirect('/tech');
    }

    public function test_login_works_and_inactive_users_are_refused(): void
    {
        $admin = $this->staff('admin');
        $this->post('/login', ['email' => $admin->email, 'password' => 'secret-password'])->assertRedirect('/admin');
        auth()->logout();
        $admin->update(['active' => false]);
        $this->post('/login', ['email' => $admin->email, 'password' => 'secret-password'])->assertSessionHasErrors('email');
    }

    public function test_pages_render_for_each_role(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $wo = WorkOrder::create(['location_id' => $this->idaho->id, 'property_id' => $this->property->id, 'tenant_id' => $this->tenant->id,
            'description' => 'Toilet runs all night', 'trade' => 'plumbing', 'status' => WorkOrder::STATUS_SCHEDULED, 'technician_id' => $tech->id,
            'scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3), 'review_reason' => 'Check this', 'needs_review_at' => now()]);

        $admin = $this->staff('admin');
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Idaho');
        $this->actingAs($admin)->get('/admin/people')->assertOk()->assertSee('Mike Plumber');
        $this->actingAs($admin)->get('/admin/people/new')->assertOk();
        $this->actingAs($admin)->get('/queue')->assertOk()->assertSee('Check this');
        $this->actingAs($admin)->get('/work-orders/'.$wo->id)->assertOk()->assertSee('Toilet runs all night');
        $this->actingAs($tech->user)->get('/tech')->assertOk()->assertSee('Maple Ct');
        $this->actingAs($tech->user)->get('/tech/jobs/'.$wo->id)->assertOk()->assertSee('On my way');
    }

    public function test_techs_cannot_see_the_queue_or_other_techs_jobs(): void
    {
        $mike = $this->tech('Mike', ['plumbing' => 3]);
        $dana = $this->tech('Dana', ['electrical' => 3]);
        $wo = WorkOrder::create(['location_id' => $this->idaho->id, 'description' => 'x', 'technician_id' => $dana->id, 'status' => WorkOrder::STATUS_SCHEDULED]);

        $this->actingAs($mike->user)->get('/queue')->assertForbidden();
        $this->actingAs($mike->user)->get('/admin')->assertForbidden();
        $this->actingAs($mike->user)->get('/tech/jobs/'.$wo->id)->assertForbidden();
        $this->actingAs($this->staff('scheduler'))->get('/admin')->assertForbidden();
    }

    public function test_admin_can_add_a_tech_with_skills(): void
    {
        $this->actingAs($this->staff('admin'))->post('/admin/people', [
            'name' => 'New Tech', 'email' => 'new@example.test', 'password' => 'long-enough-pw', 'role' => 'tech',
            'phone' => '208-555-0101', 'location_id' => $this->idaho->id, 'active' => 1, 'work_days' => [1, 2, 3],
            'skills' => [Skill::where('slug', 'hvac')->value('id') => 3],
        ])->assertRedirect('/admin/people');

        $user = User::where('email', 'new@example.test')->first();
        $this->assertSame('+12085550101', $user->phone);
        $this->assertSame(3, $user->technician->fresh('skills')->skillLevel('hvac'));
        $this->assertSame([1, 2, 3], $user->technician->workDays());
        $this->assertNotNull($user->technician->truck);
    }
}
