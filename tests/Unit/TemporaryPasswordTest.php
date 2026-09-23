<?php

namespace Tests\Unit;

use App\Support\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The generated handover credential, checked without booting the app.
 *
 * Tourism reads this string aloud or writes it on a slip of paper, so two
 * properties matter beyond strength: it always satisfies the same rules a
 * typed password has to, and it contains no glyph anyone can misread.
 *
 * Every assertion runs over many draws, because a generator that is usually
 * right is a generator that locks somebody out eventually.
 */
class TemporaryPasswordTest extends TestCase
{
    /** Enough draws that a one-in-a-hundred flaw shows up. */
    private const DRAWS = 200;

    /** @return list<string> */
    private function draws(): array
    {
        return array_map(
            fn () => PasswordPolicy::generateTemporary(),
            range(1, self::DRAWS)
        );
    }

    public function test_it_is_sixteen_characters_in_four_readable_groups(): void
    {
        foreach ($this->draws() as $password) {
            $groups = explode('-', $password);

            $this->assertCount(4, $groups, "Not four groups: {$password}");

            foreach ($groups as $group) {
                $this->assertSame(4, strlen($group), "Group is not four characters: {$password}");
            }
        }
    }

    /**
     * I, O, l, o, 0 and 1 are gone on purpose: nobody should be locked out
     * arguing about a 1 against an l.
     */
    public function test_it_never_contains_a_glyph_that_can_be_misread(): void
    {
        foreach ($this->draws() as $password) {
            foreach (['I', 'O', 'l', 'o', '0', '1'] as $ambiguous) {
                $this->assertStringNotContainsString(
                    $ambiguous,
                    $password,
                    "Ambiguous character {$ambiguous} in: {$password}"
                );
            }
        }
    }

    /**
     * The generator must clear the bar it sets for everyone else - one of each
     * character class, every single time, not on average.
     */
    public function test_every_draw_carries_all_four_character_classes(): void
    {
        foreach ($this->draws() as $password) {
            $this->assertMatchesRegularExpression('/[A-Z]/', $password, "No upper case: {$password}");
            $this->assertMatchesRegularExpression('/[a-z]/', $password, "No lower case: {$password}");
            $this->assertMatchesRegularExpression('/[2-9]/', $password, "No digit: {$password}");
            $this->assertMatchesRegularExpression('/[@#$%*?]/', $password, "No symbol: {$password}");
        }
    }

    /** Sixteen characters clears the twelve-character floor with room to spare. */
    public function test_it_is_comfortably_longer_than_the_minimum_length(): void
    {
        foreach ($this->draws() as $password) {
            $this->assertGreaterThan(
                PasswordPolicy::MIN_LENGTH,
                strlen(str_replace('-', '', $password))
            );
        }
    }

    public function test_two_draws_are_not_the_same_password(): void
    {
        $draws = $this->draws();

        $this->assertCount(
            count($draws),
            array_unique($draws),
            'The generator repeated itself.'
        );
    }
}
