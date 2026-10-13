<?php

declare(strict_types=1);

// Two layouts, one entry point:
//  - repo / dev:  public/ sits inside the project root
//  - Hostinger:   public_html/ holds this file and an app/ directory sits next to it, above the web root
$root = is_dir(__DIR__ . '/../app') ? (string) realpath(__DIR__ . '/../app') : dirname(__DIR__);

require $root . '/vendor/autoload.php';

\App\Kernel::create($root, __DIR__)->run();
