<?php

namespace Tests\Feature\Operations;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Tests\PmsTestCase;

/**
 * Paging through a list sorted by a value many rows share (every invoice issued today has the same issue date) must show
 * each row once. Without a tiebreaker the database may order the ties differently on each page.
 */
class ListPagingTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Invoice::query()->delete();

        foreach (range(1, 60) as $i) {
            $this->w->invoice(['id' => sprintf('inv_page_%03d', $i), 'code' => sprintf('INV-P%03d', $i), 'issueDate' => '2026-11-20', 'dueDate' => '2026-12-04']);
        }
    }

    /** @return array<int,string> every id the list returns when read $perPage at a time */
    private function pageThrough(string $query, int $perPage = 7): array
    {
        $ids = [];
        for ($page = 1; $page <= 20; $page++) {
            $rows = $this->as($this->w->admin)->getJson("/api/v1/invoices?per_page={$perPage}&page={$page}{$query}")->assertOk()->json('data');
            $ids = array_merge($ids, array_column($rows, 'id'));
            if (count($rows) < $perPage) {
                break;
            }
        }

        return $ids;
    }

    public function test_paging_a_list_sorted_by_a_shared_value_shows_every_row_exactly_once(): void
    {
        foreach (['', '&sort=issueDate', '&sort=-issueDate', '&sort=dueDate', '&sort=-dueDate', '&sort=status'] as $sort) {
            $ids = $this->pageThrough($sort);

            $this->assertCount(60, $ids, "sort{$sort}: rows returned");
            $this->assertCount(60, array_unique($ids), "sort{$sort}: a row showed up twice");
        }
    }

    public function test_the_order_is_the_same_every_time_it_is_asked_for(): void
    {
        $this->assertSame($this->pageThrough('&sort=issueDate'), $this->pageThrough('&sort=issueDate'));
    }

    public function test_a_shared_value_is_ordered_by_the_primary_key_as_the_tiebreaker(): void
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->as($this->w->admin)->getJson('/api/v1/invoices?sort=issueDate')->assertOk();

        $listing = collect($sql)->first(fn ($s) => str_contains($s, 'from "invoices"') && str_contains($s, 'order by'));
        $this->assertMatchesRegularExpression('/order by "issueDate" asc, "invoices"\."id" asc/', (string) $listing);
    }

    public function test_sorting_by_the_primary_key_itself_adds_no_second_ordering(): void
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->as($this->w->admin)->getJson('/api/v1/invoices?sort=-id')->assertOk();

        $listing = collect($sql)->first(fn ($s) => str_contains($s, 'from "invoices"') && str_contains($s, 'order by'));
        $this->assertStringContainsString('order by "id" desc', (string) $listing);
        $this->assertStringNotContainsString('"invoices"."id" asc', (string) $listing);
    }

    public function test_the_tiebreaker_applies_to_every_list_not_only_invoices(): void
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->as($this->w->admin)->getJson('/api/v1/leases')->assertOk();

        $listing = collect($sql)->first(fn ($s) => str_contains($s, 'from "leases"') && str_contains($s, 'order by'));
        $this->assertMatchesRegularExpression('/order by "startDate" desc, "leases"\."id" asc/', (string) $listing);
    }
}
