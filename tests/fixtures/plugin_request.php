<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$sessionPath = (string)getenv('N3_TEST_SESSION_PATH');
$errorLog = (string)getenv('N3_TEST_ERROR_LOG');
if ($sessionPath !== '') ini_set('session.save_path', $sessionPath);
if ($errorLog !== '') ini_set('error_log', $errorLog);

$_SERVER['REQUEST_METHOD'] = (string)(getenv('N3_TEST_METHOD') ?: 'GET');
$_SERVER['REQUEST_URI'] = (string)(getenv('N3_TEST_PATH') ?: '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
if (is_string($query)) parse_str($query, $_GET);
$probeHeader = (string)getenv('N3_TEST_PROBE_HEADER');
if ($probeHeader !== '') $_SERVER['HTTP_X_PLUGIN_PROBE'] = $probeHeader;

$sessionId = (string)getenv('N3_TEST_SESSION_ID');
if ($sessionId !== '') $_COOKIE['n3_session'] = $sessionId;
$csrf = (string)getenv('N3_TEST_CSRF');
if ($csrf !== '') $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;

require $projectRoot . '/public/index.php';
