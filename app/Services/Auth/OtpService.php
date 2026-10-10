<?php

namespace App\Services\Auth;

use App\Models\PasswordResetOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OtpService
{
    /**
     * Compute HMAC-SHA256 hash using the application key.
     */
    protected function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /**
     * Compute SHA-256 hash for reset tokens.
     */
    protected function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Generate a new 4-digit OTP valid for configured seconds.
     */
    public function generateOtp(string $email): string
    {
        // Invalidate previous pending OTP requests for this email
        PasswordResetOtp::where('email', $email)
            ->whereNull('used_at')
            ->delete();

        $otp = sprintf('%04d', random_int(0, 9999));
        $otpHash = $this->hmac($otp);
        $expirationSeconds = config('auth.otp_expiration_seconds', 90);

        PasswordResetOtp::create([
            'email' => $email,
            'otp_hash' => $otpHash,
            'expires_at' => now()->addSeconds($expirationSeconds),
            'attempts' => 0,
            'reset_token_hash' => null,
            'reset_token_expires_at' => null,
            'used_at' => null,
        ]);

        return $otp;
    }

    /**
     * Verify a submitted OTP.
     *
     * @return array{
     *     success: bool,
     *     reset_token?: string,
     *     expires_in?: int,
     *     error?: string,
     *     status_code?: int
     * }
     */
    public function verifyOtp(string $email, string $otp): array
    {
        return DB::transaction(function () use ($email, $otp) {
            $record = PasswordResetOtp::where('email', $email)
                ->whereNull('used_at')
                ->whereNull('reset_token_hash')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return [
                    'success' => false,
                    'error' => 'No active OTP request found for this email address.',
                    'status_code' => 400,
                ];
            }

            if ($record->isOtpExpired()) {
                return [
                    'success' => false,
                    'error' => 'The verification code has expired. Please request a new code.',
                    'status_code' => 422,
                ];
            }

            if ($record->hasExceededAttempts()) {
                return [
                    'success' => false,
                    'error' => 'Maximum verification attempts exceeded. Please request a new code.',
                    'status_code' => 429,
                ];
            }

            $record->attempts++;

            $expectedHash = $this->hmac($otp);
            if (! hash_equals($record->otp_hash, $expectedHash)) {
                $maxAttempts = config('auth.otp_max_attempts', 3);
                $remaining = max(0, $maxAttempts - $record->attempts);

                $record->save();

                return [
                    'success' => false,
                    'error' => "Invalid verification code. Attempts remaining: {$remaining}.",
                    'status_code' => 422,
                ];
            }

            // Issue 64-character secure single-use reset token
            $resetToken = Str::random(64);
            $resetTokenHash = $this->hashToken($resetToken);

            $record->update([
                'reset_token_hash' => $resetTokenHash,
                'reset_token_expires_at' => now()->addMinutes(5),
                'attempts' => $record->attempts,
            ]);

            return [
                'success' => true,
                'reset_token' => $resetToken,
                'expires_in' => 300,
            ];
        });
    }

    /**
     * Validate an issued reset token.
     */
    public function validateResetToken(string $email, string $resetToken): ?PasswordResetOtp
    {
        $hash = $this->hashToken($resetToken);

        $record = PasswordResetOtp::where('email', $email)
            ->where('reset_token_hash', $hash)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $record || ! $record->isResetTokenValid()) {
            return null;
        }

        return $record;
    }

    /**
     * Mark the reset token as used.
     */
    public function markResetTokenUsed(PasswordResetOtp $record): bool
    {
        return $record->newQuery()
            ->where('id', $record->id)
            ->whereNull('used_at')
            ->update([
                'used_at' => now(),
            ]) > 0;
    }
}
