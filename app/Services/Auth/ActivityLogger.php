<?php

namespace App\Services\Auth;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogger
{
    /**
     * Keys that must never be recorded in audit logs.
     *
     * @var list<string>
     */
    protected static array $scrubbedKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'plain_token',
        'otp',
        'otp_code',
        'reset_token',
        'secret',
        'authorization',
    ];

    /**
     * Record an audit log entry with scrubbed details.
     *
     * @param  array<string, mixed>  $details
     */
    public function log(
        ?int $userId,
        string $action,
        ?Request $request = null,
        array $details = []
    ): ActivityLog {
        $ip = $request ? $request->ip() : request()->ip();
        $userAgent = $request ? $request->userAgent() : request()->userAgent();

        return ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'details' => $this->scrubSensitiveData($details),
        ]);
    }

    /**
     * Recursively remove sensitive keys from details array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function scrubSensitiveData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::$scrubbedKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->scrubSensitiveData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
