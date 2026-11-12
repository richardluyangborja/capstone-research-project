<?php

namespace App\Settings;

/**
 * The settings allow-list (module 08 slice S4, spec 19 §Settings allow-list, ADR-0030).
 *
 * One definition per writable-or-displayable key. There is no free-form key/value editor:
 * an unknown key — including anything secret-shaped (`APP_KEY`, JWT secrets, credentials,
 * `AI_SERVICE_TOKEN`) — is simply not in this table, so the API rejects it. Each entry names
 * its validation rule, the `config/` path it overrides, the environment variable that can
 * own it, the effective default, and who may write it.
 *
 * Write roles: `admin` means admin and superadmin; `superadmin` is platform-only. A null
 * write role is a read-only display key. `user.*` preference keys are deliberately absent —
 * they arrive with S9.
 */
class SettingRegistry
{
    public const CACHE_KEY = 'admin.settings.overrides';

    /** Category slugs in display order, with their labels (spec 19 §Settings categories). */
    public const CATEGORIES = [
        'organization' => 'Organization profile',
        'crm' => 'CRM behavior',
        'notifications' => 'Notifications',
        'ai' => 'AI and integrations',
        'security' => 'Security',
    ];

    /**
     * Sentinel for "the environment variable was not set": `env($name, $default)` alone
     * cannot tell an unset variable from a literal default, and `source` must be honest.
     */
    private const ENV_SENTINEL = '__unset__';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // --- Organization profile (presentation only) -----------------------------
            [
                'key' => 'organization.display_name',
                'category' => 'organization',
                'label' => 'Organization display name',
                'type' => 'string',
                'rule' => 'required|string|min:1|max:120',
                'config' => null,
                'env' => null,
                'default' => 'Primepower Manpower Services',
                'scope' => 'organization',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'organization.support_email',
                'category' => 'organization',
                'label' => 'Support email',
                'type' => 'email',
                'rule' => 'required|email',
                'config' => null,
                'env' => null,
                'default' => 'support@primepower.test',
                'scope' => 'organization',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'organization.logo_url',
                'category' => 'organization',
                'label' => 'Logo URL',
                'type' => 'url',
                'rule' => 'nullable|url:http,https',
                'config' => null,
                'env' => null,
                'default' => null,
                'scope' => 'organization',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'organization.timezone',
                'category' => 'organization',
                'label' => 'Business timezone',
                'type' => 'enum',
                'options' => ['Asia/Manila'],
                'rule' => 'required|in:Asia/Manila',
                'config' => 'crm.timezone',
                'env' => 'CRM_TIMEZONE',
                'default' => 'Asia/Manila',
                'scope' => 'organization',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'organization.locale',
                'category' => 'organization',
                'label' => 'Display locale',
                'type' => 'enum',
                'options' => ['en-PH'],
                'rule' => 'required|in:en-PH',
                'config' => null,
                'env' => null,
                'default' => 'en-PH',
                'scope' => 'organization',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'organization.currency',
                'category' => 'organization',
                'label' => 'Currency',
                'type' => 'enum',
                'options' => ['PHP'],
                'rule' => 'required|in:PHP',
                'config' => null,
                'env' => null,
                'default' => 'PHP',
                'scope' => 'organization',
                'write_role' => null,
                'read_only' => true,
            ],

            // --- CRM behavior (spec 19; config/crm.php owns the paths) ----------------
            [
                'key' => 'crm.aging.stale_days',
                'category' => 'crm',
                'label' => 'Lead stale threshold (days)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:365',
                'config' => 'crm.aging.stale_days',
                'env' => 'CRM_STALE_DAYS',
                'default' => 14,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.aging.at_risk_days',
                'category' => 'crm',
                'label' => 'Lead at-risk threshold (days)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:365',
                'config' => 'crm.aging.at_risk_days',
                'env' => 'CRM_AT_RISK_DAYS',
                'default' => 30,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.aging.archive_days',
                'category' => 'crm',
                'label' => 'Lead archive threshold (days)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:365',
                'config' => 'crm.aging.archive_days',
                'env' => 'CRM_ARCHIVE_DAYS',
                'default' => 60,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.pipeline.stuck_days',
                'category' => 'crm',
                'label' => 'Stalled deal threshold (days)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:365',
                'config' => 'crm.pipeline.stuck_days',
                'env' => 'CRM_DEAL_STUCK_DAYS',
                'default' => 21,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.follow_ups.check_in_days',
                'category' => 'crm',
                'label' => 'Client check-in interval (days)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:365',
                'config' => 'crm.follow_ups.check_in_days',
                'env' => 'CRM_FOLLOW_UP_CHECK_IN_DAYS',
                'default' => 45,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.attachments.max_kb',
                'category' => 'crm',
                'label' => 'Attachment size ceiling (KB)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:10240',
                'config' => 'crm.attachments.max_kb',
                'env' => 'CRM_ATTACHMENT_MAX_KB',
                'default' => 10240,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.attachments.max_files',
                'category' => 'crm',
                'label' => 'Attachments per record',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:20',
                'config' => 'crm.attachments.max_files',
                'env' => 'CRM_ATTACHMENT_MAX_FILES',
                'default' => 5,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.contracts.expiring_soon_days',
                'category' => 'crm',
                'label' => 'Contract expiring-soon window (days)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:365',
                'config' => 'crm.contracts.expiring_soon_days',
                'env' => 'CRM_CONTRACT_EXPIRING_DAYS',
                'default' => 30,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.surveys.invitation_days',
                'category' => 'crm',
                'label' => 'Survey link lifetime (days)',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:90',
                'config' => 'crm.surveys.invitation_days',
                'env' => 'CRM_SURVEY_INVITATION_DAYS',
                'default' => 14,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.surveys.attention_csat_max',
                'category' => 'crm',
                'label' => 'Attention threshold — CSAT at or below',
                'type' => 'decimal',
                'rule' => 'required|numeric|min:1|max:5',
                'config' => 'crm.surveys.attention_csat_max',
                'env' => 'CRM_SURVEY_ATTENTION_CSAT_MAX',
                'default' => 3,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.surveys.attention_nps_max',
                'category' => 'crm',
                'label' => 'Attention threshold — NPS at or below',
                'type' => 'int',
                'rule' => 'required|integer|min:0|max:10',
                'config' => 'crm.surveys.attention_nps_max',
                'env' => 'CRM_SURVEY_ATTENTION_NPS_MAX',
                'default' => 6,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.surveys.public_url',
                'category' => 'crm',
                'label' => 'Survey public link origin',
                'type' => 'url',
                'rule' => 'required|url:http,https',
                'config' => 'crm.surveys.public_url',
                'env' => 'CRM_SURVEY_URL',
                'default' => 'http://localhost:5173',
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.integrations.email.simulate_failure',
                'category' => 'crm',
                'label' => 'Mocked email — simulate failures',
                'type' => 'bool',
                'rule' => 'boolean',
                'config' => 'crm.integrations.email.simulate_failure',
                'env' => 'CRM_EMAIL_SIMULATE_FAILURE',
                'default' => false,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.integrations.hr.simulate_failure',
                'category' => 'crm',
                'label' => 'Mocked HR feed — simulate failures',
                'type' => 'bool',
                'rule' => 'boolean',
                'config' => 'crm.integrations.hr.simulate_failure',
                'env' => 'CRM_HR_SIMULATE_FAILURE',
                'default' => false,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.employees.quota_default_php',
                'category' => 'crm',
                'label' => 'Default monthly sales quota (PHP)',
                'type' => 'decimal',
                'rule' => 'required|numeric|min:0|max:1000000000',
                'config' => 'crm.employees.quota_default_php',
                'env' => 'CRM_EMPLOYEE_QUOTA_DEFAULT_PHP',
                'default' => 500000,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.employees.commission_rate_percent',
                'category' => 'crm',
                'label' => 'Default sales commission rate (%)',
                'type' => 'decimal',
                'rule' => 'required|numeric|min:0|max:100',
                'config' => 'crm.employees.commission_rate_percent',
                'env' => 'CRM_EMPLOYEE_COMMISSION_RATE_PERCENT',
                'default' => 2.5,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.employees.attendance_benchmark_percent',
                'category' => 'crm',
                'label' => 'Attendance benchmark (%)',
                'type' => 'decimal',
                'rule' => 'required|numeric|min:0|max:100',
                'config' => 'crm.employees.attendance_benchmark_percent',
                'env' => 'CRM_EMPLOYEE_ATTENDANCE_BENCHMARK_PERCENT',
                'default' => 95.0,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.employees.salary_mask_for_managers',
                'category' => 'crm',
                'label' => 'Mask team salaries from managers',
                'type' => 'bool',
                'rule' => 'boolean',
                'config' => 'crm.employees.salary_mask_for_managers',
                'env' => 'CRM_EMPLOYEE_SALARY_MASK_FOR_MANAGERS',
                'default' => true,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.integrations.core2_employees.simulate_failure',
                'category' => 'crm',
                'label' => 'Core 2 employee feed — simulate failure',
                'type' => 'bool',
                'rule' => 'boolean',
                'config' => 'crm.integrations.core2_employees.simulate_failure',
                'env' => 'CRM_CORE2_SIMULATE_FAILURE',
                'default' => false,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'crm.integrations.service_handoff.simulate_failure',
                'category' => 'crm',
                'label' => 'Service handoff failure flag (historical)',
                'type' => 'bool',
                'rule' => 'boolean',
                'config' => 'crm.integrations.service_handoff.simulate_failure',
                'env' => 'CRM_HANDOFF_SIMULATE_FAILURE',
                'default' => false,
                'scope' => 'system',
                'write_role' => null,
                'read_only' => true,
            ],

            // --- Notifications --------------------------------------------------------
            [
                'key' => 'notifications.database_enabled',
                'category' => 'notifications',
                'label' => 'In-app notifications enabled',
                'type' => 'bool',
                'rule' => 'boolean',
                'config' => 'notifications.database_enabled',
                'env' => null,
                'default' => true,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],

            // --- AI and integrations --------------------------------------------------
            [
                'key' => 'ai.reports.enabled',
                'category' => 'ai',
                'label' => 'AI management reports enabled',
                'type' => 'bool',
                'rule' => 'boolean',
                // The runtime switch is `config/ai.php`'s `report` block (singular); the
                // public setting key keeps the spec's plural category spelling.
                'config' => 'ai.report.enabled',
                'env' => 'AI_REPORT_ENABLED',
                'default' => true,
                'scope' => 'system',
                'write_role' => 'admin',
                'read_only' => false,
            ],
            [
                'key' => 'ai.reports.service_url',
                'category' => 'ai',
                'label' => 'AI service URL',
                'type' => 'url',
                'rule' => 'required|url:http,https',
                'config' => 'ai.report.service_url',
                'env' => 'AI_SERVICE_URL',
                'default' => 'http://127.0.0.1:8001',
                'scope' => 'system',
                'write_role' => null,
                'read_only' => true,
            ],

            // --- Security (platform operator only) ------------------------------------
            [
                'key' => 'security.session_lifetime_minutes',
                'category' => 'security',
                'label' => 'Session lifetime (minutes)',
                'type' => 'int',
                'rule' => 'required|integer|min:15|max:1440',
                'config' => null,
                'env' => null,
                'default' => 480,
                'scope' => 'system',
                'write_role' => 'superadmin',
                'read_only' => false,
            ],
            [
                'key' => 'security.login_throttle_per_minute',
                'category' => 'security',
                'label' => 'Login attempts allowed per minute',
                'type' => 'int',
                'rule' => 'required|integer|min:1|max:60',
                'config' => null,
                'env' => null,
                'default' => 5,
                'scope' => 'system',
                'write_role' => 'superadmin',
                'read_only' => false,
            ],
            [
                'key' => 'security.password_min_length',
                'category' => 'security',
                'label' => 'Minimum password length',
                'type' => 'int',
                'rule' => 'required|integer|min:8|max:64',
                'config' => null,
                'env' => null,
                'default' => 8,
                'scope' => 'system',
                'write_role' => 'superadmin',
                'read_only' => false,
            ],
            [
                'key' => 'security.mfa_required',
                'category' => 'security',
                'label' => 'Require multi-factor authentication',
                'type' => 'bool',
                'rule' => 'boolean',
                'config' => null,
                'env' => null,
                'default' => false,
                'scope' => 'system',
                'write_role' => null,
                'read_only' => true,
            ],
        ];
    }

    /**
     * One definition by key; `null` when the key is not allow-listed (an unknown or secret
     * key never has a definition to find).
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * True when the environment variable that owns this key was explicitly set. A key with
     * no owning variable can never be environment-sourced.
     */
    public static function envIsSet(array $definition): bool
    {
        return $definition['env'] !== null
            && env($definition['env'], self::ENV_SENTINEL) !== self::ENV_SENTINEL;
    }
}
