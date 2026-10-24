<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | OTP Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for Password Reset OTP behavior.
    |
    */

    'otp_expiration_seconds' => (int) env('OTP_EXPIRATION_SECONDS', 90),
    'otp_cooldown_seconds' => (int) env('OTP_COOLDOWN_SECONDS', 30),
    'otp_max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),
    'otp_max_sends' => (int) env('OTP_MAX_SENDS', 6),
    'otp_send_window_minutes' => (int) env('OTP_SEND_WINDOW_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Remember Me & Token Lifetimes
    |--------------------------------------------------------------------------
    */

    'access_token_lifetime_seconds' => (int) env('ACCESS_TOKEN_LIFETIME_SECONDS', 3600),
    'remember_session_lifetime_days' => (int) env('REMEMBER_SESSION_LIFETIME_DAYS', 30),
    'remember_cookie_name' => env('REMEMBER_COOKIE_NAME', 'waai_remember_token'),
    'remember_cookie_path' => env('REMEMBER_COOKIE_PATH', '/api/v1/auth'),
    'remember_cookie_domain' => env('SESSION_DOMAIN', null),
    'remember_cookie_secure' => env('REMEMBER_COOKIE_SECURE', null),
    'remember_cookie_same_site' => env('REMEMBER_COOKIE_SAME_SITE', 'lax'),
    'rotation_grace_period_seconds' => (int) env('ROTATION_GRACE_PERIOD_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Layered Login Rate Limiting
    |--------------------------------------------------------------------------
    */

    'failed_login_email_ip_limit' => (int) env('FAILED_LOGIN_EMAIL_IP_LIMIT', 5),
    'failed_login_ip_limit' => (int) env('FAILED_LOGIN_IP_LIMIT', 10),
    'failed_login_ip_15m_limit' => (int) env('FAILED_LOGIN_IP_15M_LIMIT', 50),

];
