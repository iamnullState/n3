<?php
declare(strict_types=1);

$marker = (string)getenv('N3_PLUGIN_BOOT_MARKER');
if ($marker !== '') file_put_contents($marker, "throwing-probe\n", FILE_APPEND | LOCK_EX);
throw new RuntimeException('expected request lifecycle bootstrap failure');
