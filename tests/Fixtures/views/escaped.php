<?php

declare(strict_types=1);

/** @var array<string, mixed> $viewData */
/** @var Closure(mixed): string $escape */
?>
<p><?= $escape($viewData['value']) ?></p>
