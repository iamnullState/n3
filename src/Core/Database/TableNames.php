<?php

declare(strict_types=1);

namespace N3\Core\Database;

final readonly class TableNames
{
    public const MAX_PREFIX_BYTES = 24;

    /** @var array<string, true> */
    private const IDENTIFIERS = [
        'schema_migrations' => true,
        'installation_state' => true,
        'users' => true,
        'email_verification_tokens' => true,
        'rate_limit_buckets' => true,
        'security_events' => true,
        'password_reset_tokens' => true,
        'pages' => true,
        'content_events' => true,
        'modules' => true,
        'module_events' => true,
        'jobs' => true,
        'job_events' => true,
        'webhook_receipts' => true,
        'module_migrations' => true,
        'site_settings' => true,
        'site_navigation_items' => true,
        'site_events' => true,
        'm_n3_analytics_feadc5f8_hourly_metrics' => true,
        'm_n3_media_034553f6_assets' => true,
        'm_n3_media_034553f6_events' => true,
        'm_n3_media_034553f6_upload_limits' => true,
        'm_n3_media_034553f6_page_attachments' => true,
        'm_n3_blog_0356bd27_posts' => true,
        'm_n3_blog_0356bd27_events' => true,
        'users_email_normalized_unique' => true,
        'users_account_status_check' => true,
        'email_verification_token_unique' => true,
        'email_verification_user_fk' => true,
        'email_verification_user_index' => true,
        'rate_limit_updated_index' => true,
        'security_event_user_fk' => true,
        'security_event_created_index' => true,
        'security_event_type_index' => true,
        'users_role_key_check' => true,
        'users_role_index' => true,
        'password_reset_token_unique' => true,
        'password_reset_user_fk' => true,
        'password_reset_user_index' => true,
        'pages_slug_unique' => true,
        'pages_status_check' => true,
        'pages_author_fk' => true,
        'pages_updated_by_fk' => true,
        'pages_public_index' => true,
        'pages_updated_index' => true,
        'content_event_page_fk' => true,
        'content_event_actor_fk' => true,
        'content_event_page_index' => true,
        'content_event_created_index' => true,
        'modules_state_check' => true,
        'module_events_module_fk' => true,
        'module_events_type_check' => true,
        'module_events_module_index' => true,
        'module_events_created_index' => true,
        'jobs_status_check' => true,
        'jobs_attempts_check' => true,
        'jobs_payload_check' => true,
        'jobs_idempotency_unique' => true,
        'jobs_claim_index' => true,
        'jobs_lease_index' => true,
        'jobs_owner_index' => true,
        'job_events_job_fk' => true,
        'job_events_type_check' => true,
        'job_events_job_index' => true,
        'job_events_created_index' => true,
        'webhook_receipts_expiry_index' => true,
        'module_migrations_applied_index' => true,
        'site_settings_singleton' => true,
        'site_settings_color' => true,
        'site_settings_actor_fk' => true,
        'site_navigation_page_unique' => true,
        'site_navigation_position_unique' => true,
        'site_navigation_page_fk' => true,
        'site_navigation_public_index' => true,
        'site_event_actor_fk' => true,
        'site_event_time_index' => true,
        'installation_state_singleton' => true,
        'installation_state_status_check' => true,
        'uq_media_public_id' => true,
        'idx_media_created' => true,
        'idx_media_events_time' => true,
        'idx_media_limits_window' => true,
        'idx_page_media_asset' => true,
        'chk_page_media_alt' => true,
        'fk_page_media_page' => true,
        'fk_page_media_asset' => true,
        'uq_blog_slug' => true,
        'idx_blog_public' => true,
        'idx_blog_admin' => true,
        'ck_blog_status' => true,
        'ck_blog_publication' => true,
        'fk_blog_author' => true,
        'fk_blog_editor' => true,
        'idx_blog_events_post' => true,
        'idx_blog_events_time' => true,
        'ck_blog_event_type' => true,
        'ck_blog_event_from' => true,
        'ck_blog_event_to' => true,
        'fk_blog_event_post' => true,
        'fk_blog_event_actor' => true,
    ];

    public function __construct(private string $prefix = '')
    {
        if ($prefix !== '' && (strlen($prefix) > self::MAX_PREFIX_BYTES
            || preg_match('/^[a-z][a-z0-9_]{0,22}_$/D', $prefix) !== 1)) {
            throw new DatabaseException(
                'DB_TABLE_PREFIX must be empty or 2-24 lowercase ASCII characters, start with a letter, and end with an underscore.',
            );
        }

        foreach (array_keys(self::IDENTIFIERS) as $identifier) {
            if (strlen($prefix . $identifier) > 64) {
                throw new DatabaseException('DB_TABLE_PREFIX produces a MariaDB identifier longer than 64 bytes.');
            }
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function physical(string $logical): string
    {
        if (!isset(self::IDENTIFIERS[$logical])) {
            throw new DatabaseException(sprintf('Unknown managed database identifier "%s".', $logical));
        }

        return $this->prefix . $logical;
    }

    public function rewrite(string $sql): string
    {
        if ($this->prefix === '') {
            return $sql;
        }

        $rewritten = preg_replace_callback(
            '/\'(?:\'\'|\\\\.|[^\'])*\'|"(?:""|\\\\.|[^"])*"|`([^`]+)`|\b([A-Za-z_][A-Za-z0-9_]*)\b/',
            function (array $match): string {
                $first = $match[0][0];
                if ($first === "'" || $first === '"') {
                    return $match[0];
                }

                $quoted = $first === '`';
                $identifier = $quoted ? $match[1] : $match[2];
                if (!isset(self::IDENTIFIERS[$identifier])) {
                    return $match[0];
                }

                $physical = $this->prefix . $identifier;

                return $quoted ? '`' . $physical . '`' : $physical;
            },
            $sql,
        );

        if (!is_string($rewritten)) {
            throw new DatabaseException('Unable to resolve managed database identifiers.');
        }

        return $rewritten;
    }
}
