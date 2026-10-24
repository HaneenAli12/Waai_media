<?php

namespace Tests\Unit;

use App\Models\Counter;
use PHPUnit\Framework\TestCase;

class CounterModelTest extends TestCase
{
    public function test_fixed_counters_contains_exactly_five_predefined_records(): void
    {
        $this->assertCount(5, Counter::FIXED_COUNTERS);
    }

    public function test_fixed_counters_match_exact_specification(): void
    {
        $expected = [
            1 => [
                'key' => 'clients_served',
                'name' => 'Clients Served',
                'value' => 15,
                'order' => 1,
            ],
            2 => [
                'key' => 'campaigns_launched',
                'name' => 'Campaigns Launched',
                'value' => 400,
                'order' => 2,
            ],
            3 => [
                'key' => 'social_content_published',
                'name' => 'Social Content Published',
                'value' => 2000,
                'order' => 3,
            ],
            4 => [
                'key' => 'industries_covered',
                'name' => 'Industries Covered',
                'value' => 18,
                'order' => 4,
            ],
            5 => [
                'key' => 'marketing_experts',
                'name' => 'Marketing Experts',
                'value' => 10,
                'order' => 5,
            ],
        ];

        foreach (Counter::FIXED_COUNTERS as $counter) {
            $order = $counter['order'];
            $this->assertArrayHasKey($order, $expected);
            $this->assertSame($expected[$order]['key'], $counter['key']);
            $this->assertSame($expected[$order]['name'], $counter['name']);
            $this->assertSame($expected[$order]['value'], $counter['value']);
            $this->assertSame($expected[$order]['order'], $counter['order']);
        }
    }

    public function test_valid_keys_returns_all_predefined_keys(): void
    {
        $keys = Counter::validKeys();

        $this->assertSame([
            'clients_served',
            'campaigns_launched',
            'social_content_published',
            'industries_covered',
            'marketing_experts',
        ], $keys);
    }

    public function test_valid_orders_returns_orders_one_through_five(): void
    {
        $orders = Counter::validOrders();

        $this->assertSame([1, 2, 3, 4, 5], $orders);
    }

    public function test_is_valid_key_identifies_valid_and_invalid_keys(): void
    {
        $this->assertTrue(Counter::isValidKey('clients_served'));
        $this->assertTrue(Counter::isValidKey('campaigns_launched'));
        $this->assertTrue(Counter::isValidKey('social_content_published'));
        $this->assertTrue(Counter::isValidKey('industries_covered'));
        $this->assertTrue(Counter::isValidKey('marketing_experts'));

        $this->assertFalse(Counter::isValidKey('unknown_counter'));
        $this->assertFalse(Counter::isValidKey('clients'));
        $this->assertFalse(Counter::isValidKey(''));
    }

    public function test_fillable_attributes_only_allows_value(): void
    {
        $counter = new Counter;

        // Users can only update numerical values; keys and names are immutable
        $this->assertSame(['value'], $counter->getFillable());
    }

    public function test_casts_attributes_properly(): void
    {
        $counter = new Counter;
        $casts = $counter->getCasts();

        $this->assertSame('integer', $casts['value']);
        $this->assertSame('integer', $casts['order']);
    }
}
