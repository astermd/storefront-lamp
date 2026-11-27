<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

require dirname(__DIR__) . '/vendor/autoload.php';

$app = \AsterMD\Storefront\Bootstrap\AppFactory::create(dirname(__DIR__));
$app->run();

// Tracking the visitor has no reason to wait for, sent once the response is
// out ({@see \AsterMD\Storefront\Support\AfterResponse}).
$app->getContainer()?->get(\AsterMD\Storefront\Support\AfterResponse::class)->run();
