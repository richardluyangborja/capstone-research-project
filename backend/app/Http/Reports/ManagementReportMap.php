<?php

namespace App\Http\Reports;

use App\Enums\ManagementReportVariant;

/**
 * The fixed insight/action vocabulary the model may use (spec 15 §Prompt contract).
 *
 * The model writes prose; it never writes a route. This map owns every href and every colour
 * domain, so a generated report can only point at a page that already exists. Validation also
 * enforces the length caps and the exact 3-insight / 4-action shape, turning a malformed provider
 * response into a rules fallback rather than a broken page.
 */
final class ManagementReportMap
{
    /** @var array<string, array{domain: string, href: string, link_label: string}> */
    private const INSIGHTS = [
        'attention' => [
            'domain' => 'people',
            'href' => '/satisfaction/responses?attention=true',
            'link_label' => 'Read the answers',
        ],
        'pipeline' => [
            'domain' => 'cost',
            'href' => '/pipeline',
            'link_label' => 'Open the board',
        ],
        'follow_ups' => [
            'domain' => 'activity',
            'href' => '/follow-ups',
            'link_label' => 'Review follow-ups',
        ],
        'win_rate' => [
            'domain' => 'counts',
            'href' => '/opportunities',
            'link_label' => 'Open the deal book',
        ],
        'stages' => [
            'domain' => 'cost',
            'href' => '/pipeline',
            'link_label' => 'Open the board',
        ],
        'stalled' => [
            'domain' => 'activity',
            'href' => '/pipeline',
            'link_label' => 'Review the board',
        ],
        'csat' => [
            'domain' => 'counts',
            'href' => '/satisfaction',
            'link_label' => 'Open satisfaction',
        ],
        'nps' => [
            'domain' => 'counts',
            'href' => '/satisfaction',
            'link_label' => 'Open satisfaction',
        ],
        'churn' => [
            'domain' => 'activity',
            'href' => '/clients',
            'link_label' => 'Review clients',
        ],
    ];

    /** @var array<string, string> */
    private const ACTIONS = [
        'open_pipeline' => '/pipeline',
        'open_board' => '/pipeline',
        'open_deals' => '/opportunities',
        'open_clients' => '/clients',
        'open_satisfaction' => '/satisfaction',
        'open_worklist' => '/follow-ups',
        'read_answers' => '/satisfaction/responses?attention=true',
        'open_followups' => '/follow-ups',
    ];

    /** @var array<string, array{insights: array<int, string>, actions: array<int, string>}> */
    private const ALLOWED = [
        'executive' => [
            'insights' => ['attention', 'pipeline', 'follow_ups', 'win_rate'],
            'actions' => ['read_answers', 'open_board', 'open_worklist', 'open_clients'],
        ],
        'pipeline' => [
            'insights' => ['stages', 'stalled', 'win_rate', 'follow_ups'],
            'actions' => ['open_pipeline', 'open_board', 'open_deals', 'open_worklist'],
        ],
        'client_health' => [
            'insights' => ['attention', 'csat', 'nps', 'churn'],
            'actions' => ['read_answers', 'open_satisfaction', 'open_clients', 'open_followups'],
        ],
    ];

    /**
     * @return array{insights: array<int, string>, actions: array<int, string>}
     */
    public static function allowed(ManagementReportVariant $variant): array
    {
        return self::ALLOWED[$variant->value];
    }

    /**
     * Validate a provider payload and map its fixed IDs to the routes and domains Laravel owns.
     *
     * @return array{headline: string, narrative: string, insights: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}|null
     */
    public static function normalize(ManagementReportVariant $variant, mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $headline = $payload['headline'] ?? null;
        $narrative = $payload['narrative'] ?? null;
        $insights = $payload['insights'] ?? null;
        $actions = $payload['actions'] ?? null;

        if (
            ! is_string($headline) || trim($headline) === '' || mb_strlen($headline) > 90
            || ! is_string($narrative) || trim($narrative) === '' || mb_strlen($narrative) > 700
            || self::containsUnsafeText($headline.' '.$narrative)
            || ! is_array($insights) || count($insights) !== 3
            || ! is_array($actions) || count($actions) !== 4
        ) {
            return null;
        }

        $allowed = self::allowed($variant);
        $mappedInsights = [];
        $seenInsights = [];

        foreach ($insights as $insight) {
            if (! is_array($insight)) {
                return null;
            }

            $id = $insight['id'] ?? null;
            $title = $insight['title'] ?? null;
            $body = $insight['body'] ?? null;

            if (
                ! is_string($id) || ! in_array($id, $allowed['insights'], true)
                || isset($seenInsights[$id])
                || ! is_string($title) || trim($title) === '' || mb_strlen($title) > 60
                || ! is_string($body) || trim($body) === '' || mb_strlen($body) > 220
                || self::containsUnsafeText($title.' '.$body)
            ) {
                return null;
            }

            $seenInsights[$id] = true;
            $mappedInsights[] = [
                'id' => $id,
                'title' => trim($title),
                'body' => trim($body),
                'domain' => self::INSIGHTS[$id]['domain'],
                'href' => self::INSIGHTS[$id]['href'],
                'link_label' => self::INSIGHTS[$id]['link_label'],
            ];
        }

        $mappedActions = [];
        $seenActions = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                return null;
            }

            $id = $action['id'] ?? null;
            $label = $action['label'] ?? null;
            $description = $action['description'] ?? null;

            if (
                ! is_string($id) || ! in_array($id, $allowed['actions'], true)
                || isset($seenActions[$id])
                || ! is_string($label) || trim($label) === '' || mb_strlen($label) > 50
                || ! is_string($description) || trim($description) === '' || mb_strlen($description) > 140
                || self::containsUnsafeText($label.' '.$description)
            ) {
                return null;
            }

            $seenActions[$id] = true;
            $mappedActions[] = [
                'id' => $id,
                'label' => trim($label),
                'description' => trim($description),
                'href' => self::ACTIONS[$id],
            ];
        }

        return [
            'headline' => trim($headline),
            'narrative' => trim($narrative),
            'insights' => $mappedInsights,
            'actions' => $mappedActions,
        ];
    }

    /**
     * The model must not invent a URL or an email address. Phone numbers are covered by the
     * no-record-data context; this is the last cheap guard on the prose.
     */
    private static function containsUnsafeText(string $text): bool
    {
        return preg_match('/https?:\/\/|www\.[^\s]+|[\w.+-]+@[\w-]+\.[\w.-]+/i', $text) === 1;
    }
}
