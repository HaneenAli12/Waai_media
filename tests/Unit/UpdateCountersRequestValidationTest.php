<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\Counter\UpdateCountersRequest;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class UpdateCountersRequestValidationTest extends TestCase
{
    protected ValidationFactory $validatorFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->bind('translator', function () {
            $loader = new ArrayLoader;

            return new Translator($loader, 'en');
        });

        Facade::setFacadeApplication($container);
        $this->validatorFactory = new ValidationFactory($container->make('translator'), $container);
        Validator::swap($this->validatorFactory);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /**
     * Helper to run validation through UpdateCountersRequest rules.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validateData(array $data): array
    {
        $request = new UpdateCountersRequest;
        $request->merge($data);

        // Run prepareForValidation to execute payload normalization
        $reflection = new \ReflectionMethod($request, 'prepareForValidation');
        $reflection->invoke($request);

        $validator = $this->validatorFactory->make(
            $request->all(),
            $request->rules(),
            $request->messages()
        );

        return $validator->validate();
    }

    public function test_accepts_valid_single_counter_update(): void
    {
        $input = [
            'counters' => [
                ['key' => 'clients_served', 'value' => 25],
            ],
        ];

        $validated = $this->validateData($input);

        $this->assertCount(1, $validated['counters']);
        $this->assertSame('clients_served', $validated['counters'][0]['key']);
        $this->assertSame(25, $validated['counters'][0]['value']);
    }

    public function test_accepts_valid_multiple_counters_update(): void
    {
        $input = [
            'counters' => [
                ['key' => 'clients_served', 'value' => 20],
                ['key' => 'campaigns_launched', 'value' => 450],
                ['key' => 'marketing_experts', 'value' => 15],
            ],
        ];

        $validated = $this->validateData($input);

        $this->assertCount(3, $validated['counters']);
        $this->assertSame(450, $validated['counters'][1]['value']);
    }

    public function test_accepts_valid_counter_update_by_id(): void
    {
        $input = [
            'counters' => [
                ['id' => 1, 'value' => 50],
                ['id' => 3, 'value' => 2500],
            ],
        ];

        $validated = $this->validateData($input);

        $this->assertCount(2, $validated['counters']);
        $this->assertSame(1, $validated['counters'][0]['id']);
        $this->assertSame(50, $validated['counters'][0]['value']);
    }

    public function test_normalizes_associative_key_value_map_payload(): void
    {
        $input = [
            'counters' => [
                'clients_served' => 30,
                'marketing_experts' => 12,
            ],
        ];

        $validated = $this->validateData($input);

        $this->assertCount(2, $validated['counters']);
        $this->assertSame('clients_served', $validated['counters'][0]['key']);
        $this->assertSame(30, $validated['counters'][0]['value']);
        $this->assertSame('marketing_experts', $validated['counters'][1]['key']);
        $this->assertSame(12, $validated['counters'][1]['value']);
    }

    public function test_rejects_unknown_counter_key(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [
                ['key' => 'non_existent_counter', 'value' => 100],
            ],
        ]);
    }

    public function test_rejects_unknown_counter_id(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [
                ['id' => 999, 'value' => 100],
            ],
        ]);
    }

    public function test_rejects_negative_value(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [
                ['key' => 'clients_served', 'value' => -5],
            ],
        ]);
    }

    public function test_rejects_non_integer_value(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [
                ['key' => 'clients_served', 'value' => 'not_a_number'],
            ],
        ]);
    }

    public function test_rejects_float_value(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [
                ['key' => 'clients_served', 'value' => 15.75],
            ],
        ]);
    }

    public function test_rejects_value_exceeding_max_range(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [
                ['key' => 'clients_served', 'value' => 2147483648],
            ],
        ]);
    }

    public function test_rejects_empty_counters_array(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [],
        ]);
    }

    public function test_rejects_duplicate_counter_keys_in_same_request(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateData([
            'counters' => [
                ['key' => 'clients_served', 'value' => 10],
                ['key' => 'clients_served', 'value' => 20],
            ],
        ]);
    }

    public function test_authorize_allows_admin(): void
    {
        $admin = new User;
        $admin->role = 'admin';

        $request = new UpdateCountersRequest;
        $request->setUserResolver(fn () => $admin);

        $this->assertTrue($request->authorize());
    }

    public function test_authorize_allows_editor(): void
    {
        $editor = new User;
        $editor->role = 'editor';

        $request = new UpdateCountersRequest;
        $request->setUserResolver(fn () => $editor);

        $this->assertTrue($request->authorize());
    }

    public function test_authorize_denies_other_roles(): void
    {
        $viewer = new User;
        $viewer->role = 'viewer';

        $request = new UpdateCountersRequest;
        $request->setUserResolver(fn () => $viewer);

        $this->assertFalse($request->authorize());
    }

    public function test_authorize_denies_unauthenticated_user(): void
    {
        $request = new UpdateCountersRequest;
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize());
    }
}
