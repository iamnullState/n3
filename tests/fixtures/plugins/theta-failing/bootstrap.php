<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Plugin\PluginRegistry;

return static function (PluginRegistry $registry): void {
    $registry->profileCard(static fn(array $context): array => [
        'title' => 'Failed profile card',
        'body' => 'Atomic registration must discard this contribution.',
    ]);
    $registry->pageInformationRow(static fn(array $context): array => [
        'label' => 'Failed row',
        'value' => 'Atomic registration must discard this contribution.',
    ]);
    $registry->dashboardWidget([
        'title' => 'Bootstrap contribution before failure',
        'body' => 'Atomic registration must discard this contribution.',
    ]);
    $registry->route(
        'GET',
        '/api/plugins/theta-failing/partial',
        static fn(Request $request, array $user): array => ['partial' => true],
    );
    throw new RuntimeException('expected fixture bootstrap failure');
};
