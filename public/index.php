<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

require dirname(__DIR__) . '/vendor/autoload.php';

\AsterMD\Storefront\Bootstrap\AppFactory::create(dirname(__DIR__))->run();
