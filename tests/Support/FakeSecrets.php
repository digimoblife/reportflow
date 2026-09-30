<?php

namespace Tests\Support;

/**
 * Fake credentials for tests. Each value is assembled at runtime from fragments so that the
 * repository never contains a complete secret-shaped literal (GitHub secret scanning, reviewers).
 * None of them is a real credential.
 */
final class FakeSecrets
{
    public static function openAiKey(): string
    {
        return 's'.'k-'.'proj'.'-'.'abcDEF123456'.'ghiJKL789012mnoP';
    }

    public static function githubToken(): string
    {
        return 'gh'.'p_'.str_repeat('A1b2', 9);
    }

    public static function awsKeyId(): string
    {
        return 'AK'.'IA'.'ABCDEFGH'.'IJKLMNOP';
    }

    public static function googleKey(): string
    {
        return 'AI'.'za'.str_repeat('Ab1_', 8).'xyz';
    }

    public static function slackToken(): string
    {
        return 'xo'.'xb-'.'123456789012-'.'abcdefABCDEF';
    }

    public static function telegramBotToken(): string
    {
        return '123456789'.':'.str_repeat('Ab1-', 8).'xyz';
    }

    public static function jwt(): string
    {
        return 'ey'.'JhbGciOiJIUzI1NiJ9'.'.'.'ey'.'JzdWIiOiIxMjM0NTY3ODkwIn0'.'.'.'abc123DEF456';
    }

    public static function stripeKey(): string
    {
        return 's'.'k_live_'.'AbCd1234EfGh5678IjKl';
    }

    public static function pemBlock(): string
    {
        return "-----BEGIN RSA PRIVATE KEY-----\nMIIEow"."IBAAKCAQEA0Z3VS5JJcds3xfn\nabcdEFGH1234567890\n-----END RSA PRIVATE KEY-----";
    }

    public static function dbUri(): string
    {
        return 'post'.'gres'.'://app_user:'.'hunter2x'.'@db.internal:5432/appdb';
    }

    public static function password(): string
    {
        return 'Tr0ub4dor'.'&3xyz';
    }

    /**
     * A unique marker to prove a value never reaches logs, DB rows, exceptions or outgoing messages.
     */
    public static function canary(): string
    {
        return 's'.'k-'.'CANARY'.'0123456789'.'abcdefghij';
    }
}
