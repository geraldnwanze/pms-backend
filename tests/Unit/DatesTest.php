<?php

namespace Tests\Unit;

use App\Support\Dates;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class DatesTest extends TestCase
{
    public function test_a_date_is_written_the_way_the_app_shows_it(): void
    {
        $this->assertSame('3 Dec 2026', Dates::friendly('2026-12-03'));
        $this->assertSame('15 Nov 2026', Dates::friendly('2026-11-15'));
        $this->assertSame('1 Jan 2027', Dates::friendly(Carbon::parse('2027-01-01 18:30:00')));
    }

    public function test_a_timestamp_keeps_only_its_date(): void
    {
        $this->assertSame('3 Dec 2026', Dates::friendly('2026-12-03T23:59:00+00:00'));
    }

    public function test_a_missing_date_is_a_dash_not_today(): void
    {
        $this->assertSame('—', Dates::friendly(null));
        $this->assertSame('—', Dates::friendly(''));
    }
}
