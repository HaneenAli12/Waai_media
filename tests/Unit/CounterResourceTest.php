<?php

namespace Tests\Unit;

use App\Http\Resources\CounterResource;
use App\Models\Counter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class CounterResourceTest extends TestCase
{
    public function test_resource_transforms_counter_omitting_timestamps_and_internal_metadata(): void
    {
        $counter = (new Counter)->setRawAttributes([
            'id' => 1,
            'key' => 'clients_served',
            'name' => 'Clients Served',
            'value' => 45,
            'order' => 1,
            'created_at' => '2026-10-17 00:00:00',
            'updated_at' => '2026-10-17 00:00:00',
        ], true);

        $resource = new CounterResource($counter);
        $result = $resource->toArray(Request::create('/api/v1/counters'));

        $this->assertSame([
            'id' => 1,
            'key' => 'clients_served',
            'name' => 'Clients Served',
            'value' => 45,
            'order' => 1,
        ], $result);

        $this->assertArrayNotHasKey('created_at', $result);
        $this->assertArrayNotHasKey('updated_at', $result);
    }
}
