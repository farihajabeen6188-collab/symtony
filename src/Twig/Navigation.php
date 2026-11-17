<?php

declare(strict_types=1);

namespace App\Twig;

/**
 * The application's primary navigation. Single source for the sidebar and the command palette.
 */
final class Navigation
{
    /**
     * @return list<array{route: string, label: string, icon: string, section: string, keywords: string}>
     */
    public function items(): array
    {
        return [
            ['route' => 'app_overview', 'label' => 'Overview', 'icon' => 'lucide:layout-dashboard', 'section' => 'Monitor', 'keywords' => 'dashboard health home'],
            ['route' => 'app_incidents', 'label' => 'Incidents', 'icon' => 'lucide:siren', 'section' => 'Monitor', 'keywords' => 'errors failures issues'],
            ['route' => 'app_investigations', 'label' => 'Investigations', 'icon' => 'lucide:scan-search', 'section' => 'Monitor', 'keywords' => 'root cause ai analysis'],
            ['route' => 'app_events', 'label' => 'Event Explorer', 'icon' => 'lucide:list-tree', 'section' => 'Monitor', 'keywords' => 'logs traces events search'],
            ['route' => 'app_test_lab', 'label' => 'Test Lab', 'icon' => 'lucide:flask-conical', 'section' => 'Resolve', 'keywords' => 'regression phpunit tests'],
            ['route' => 'app_knowledge', 'label' => 'Knowledge Base', 'icon' => 'lucide:library', 'section' => 'Resolve', 'keywords' => 'history similar incidents'],
            ['route' => 'app_applications', 'label' => 'Applications', 'icon' => 'lucide:boxes', 'section' => 'Connect', 'keywords' => 'apps connect ingestion tokens'],
            ['route' => 'app_mcp', 'label' => 'MCP Integrations', 'icon' => 'lucide:plug', 'section' => 'Connect', 'keywords' => 'mcp claude tools agents'],
            ['route' => 'app_settings', 'label' => 'Settings', 'icon' => 'lucide:settings', 'section' => 'Connect', 'keywords' => 'profile workspace ai provider security'],
        ];
    }

    /**
     * @return array<string, list<array{route: string, label: string, icon: string, section: string, keywords: string}>>
     */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->items() as $item) {
            $groups[$item['section']][] = $item;
        }

        return $groups;
    }
}
