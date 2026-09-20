<?php

namespace Tests\Feature;

use App\Http\Middleware\SanitizeInput;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Input sanitising: tags out, ordinary text intact.
 *
 * Blade escapes on output, so a "<script>" in a name was never going to run
 * in the panel. It would run in the visitor app's candidate list, which
 * builds one line of HTML from an exhibit name, and it would sit in the
 * CSV that Tourism opens in Excel. So tags are removed on the way in.
 *
 * The second half matters as much as the first: strip_tags() would have
 * turned "children <12 free" into "children ", and nobody would have
 * noticed until a label was wrong.
 */
class InputSanitizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tag_in_a_visitor_name_is_removed_before_it_is_saved(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name'   => '<script>alert(1)</script>Ramon',
            'last_name'    => 'Cruz<img src=x onerror=alert(1)>',
            'visitor_type' => 'Tourist',
            'city'         => 'Quezon City',
        ])->assertRedirect();

        $this->assertDatabaseHas('visitors', [
            'first_name' => 'alert(1)Ramon',
            'last_name'  => 'Cruz',
        ]);
    }

    public function test_angle_brackets_that_are_not_tags_survive(): void
    {
        $this->assertSame('children <12 free, 5 < 10', SanitizeInput::scrub('children <12 free, 5 < 10'));
        $this->assertSame('a -> b', SanitizeInput::scrub('a -> b'));
    }

    public function test_control_characters_are_removed_but_newlines_kept(): void
    {
        $this->assertSame("line one\nline two", SanitizeInput::scrub("line\0 one\nline\x1F two"));
    }

    public function test_html_comments_and_closing_tags_go_too(): void
    {
        $this->assertSame('plain', SanitizeInput::scrub('<!-- hidden -->pl</b>ain'));
    }

    public function test_passwords_are_never_altered(): void
    {
        // A password with a "<b>" in it is a fine password. Cleaning it on
        // the way in would lock the person out of the account they just
        // set it on. The change-password form is where that would bite.
        $staff = Staff::factory()->create(['password' => 'Old-password-1<b>']);

        $this->actingAs($staff)->put('/my/password', [
            'current_password'      => 'Old-password-1<b>',
            'password'              => 'New-strong-passw0rd<i>',
            'password_confirmation' => 'New-strong-passw0rd<i>',
        ]);

        $this->assertTrue(\Hash::check('New-strong-passw0rd<i>', $staff->fresh()->password));
    }
}
