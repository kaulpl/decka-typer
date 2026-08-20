<?php
if (!defined('ABSPATH')) exit;

/**
 * Keeps ordinary Typer users signed in persistently.
 *
 * WordPress still owns the session token and explicit logout invalidates the
 * cookie/session in the standard way. Privileged administrator sessions keep
 * WordPress' normal lifetime for security reasons.
 */
class DT_Session_Persistence {
    private const TYPER_SESSION_TTL = 10 * YEAR_IN_SECONDS;

    public static function register(): void {
        add_filter('auth_cookie_expiration', [__CLASS__, 'cookie_expiration'], 20, 3);
        add_action('wp_login', [__CLASS__, 'persist_password_login'], 20, 2);
    }

    public static function cookie_expiration(int $length, int $userId, bool $remember): int {
        if (!$remember || !self::is_regular_typer_user($userId)) return $length;
        return self::TYPER_SESSION_TTL;
    }

    /**
     * Social login already calls wp_set_auth_cookie(..., true). This hook also
     * turns a normal WordPress login of a regular Typer user into a persistent
     * login, so users do not unexpectedly lose their Typer session.
     */
    public static function persist_password_login(string $userLogin, WP_User $user): void {
        if (!self::is_regular_typer_user((int)$user->ID)) return;
        wp_set_auth_cookie((int)$user->ID, true, is_ssl());
    }

    private static function is_regular_typer_user(int $userId): bool {
        if ($userId <= 0) return false;
        $user = get_userdata($userId);
        if (!$user) return false;

        // Do not silently extend high-privilege WordPress back-office sessions.
        if (user_can($user, 'manage_options')) return false;
        return true;
    }
}
