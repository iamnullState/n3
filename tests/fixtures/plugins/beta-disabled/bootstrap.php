<?php
declare(strict_types=1);

use N3\Plugin\PluginRegistry;

return static function (PluginRegistry $registry): void {
    $registry->profileCard(static fn(array $context): array => [
        'title' => 'Disabled profile card',
        'body' => 'This contribution must never render.',
    ]);
    $registry->pageInformationRow(static fn(array $context): array => [
        'label' => 'Disabled row',
        'value' => 'This contribution must never render.',
    ]);
    throw new RuntimeException('A disabled plugin must not execute.');
};
