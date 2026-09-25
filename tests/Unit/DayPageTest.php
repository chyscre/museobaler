<?php

namespace Tests\Unit;

use App\Models\Log;
use App\Support\DayPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DayPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_day_url_works_when_there_is_no_previous_day(): void
    {
        Log::create(['action' => 'Newest'])
            ->forceFill(['created_at' => now()])
            ->saveQuietly();
        Log::create(['action' => 'Older'])
            ->forceFill(['created_at' => now()->subDay()])
            ->saveQuietly();

        $day = DayPage::of(Log::query()->orderByDesc('created_at'), 'created_at');

        $this->assertNull($day->previous);
        $this->assertNotNull($day->next);
        $this->assertStringContainsString('page=2', $day->urlFor($day->next));
    }
}
