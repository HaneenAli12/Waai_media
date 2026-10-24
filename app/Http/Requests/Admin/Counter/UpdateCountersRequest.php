<?php

namespace App\Http\Requests\Admin\Counter;

use App\Models\Counter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have full update privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Prepare the data for validation.
     * Normalizes flexible frontend payloads (e.g., key-value dictionary or item lists)
     * into a standardized list of update operations.
     */
    protected function prepareForValidation(): void
    {
        $counters = $this->input('counters');

        if (! is_array($counters)) {
            return;
        }

        // Check if $counters is an associative map: e.g. {"clients_served": 25, "campaigns_launched": 500}
        $isAssociativeMap = array_keys($counters) !== range(0, count($counters) - 1);

        if ($isAssociativeMap) {
            $normalized = [];
            foreach ($counters as $keyOrId => $value) {
                if (is_numeric($keyOrId)) {
                    $normalized[] = [
                        'id' => (int) $keyOrId,
                        'value' => $value,
                    ];
                } else {
                    $normalized[] = [
                        'key' => (string) $keyOrId,
                        'value' => $value,
                    ];
                }
            }
            $this->merge(['counters' => $normalized]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $validKeys = Counter::validKeys();
        $validOrders = Counter::validOrders();

        return [
            'counters' => ['required', 'array', 'min:1'],
            'counters.*' => ['required', 'array'],
            'counters.*.key' => [
                'required_without:counters.*.id',
                'string',
                Rule::in($validKeys),
                'distinct',
            ],
            'counters.*.id' => [
                'required_without:counters.*.key',
                'integer',
                Rule::in($validOrders),
                'distinct',
            ],
            'counters.*.value' => [
                'required',
                'integer',
                'min:0',
                'max:2147483647',
            ],
        ];
    }

    /**
     * Custom validation messages for counter constraints.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counters.required' => 'At least one counter update must be provided.',
            'counters.array' => 'The counters payload must be an array or object.',
            'counters.min' => 'At least one counter update must be provided.',
            'counters.*.key.in' => 'Unknown counter identifier provided. Predefined system counters cannot be added or renamed.',
            'counters.*.id.in' => 'Unknown counter ID provided.',
            'counters.*.key.distinct' => 'Duplicate counter identifier submitted in the batch request.',
            'counters.*.id.distinct' => 'Duplicate counter ID submitted in the batch request.',
            'counters.*.value.required' => 'A numerical value is required for each counter.',
            'counters.*.value.integer' => 'Counter values must be valid integers.',
            'counters.*.value.min' => 'Counter values cannot be negative.',
            'counters.*.value.max' => 'Counter value exceeds the maximum allowable integer range.',
        ];
    }
}
