<?php
/**
 * CLI seeder for local testing / demos only.
 *   php seed.php            -> ensure schema + demo users + starter content
 *   php seed.php --fresh    -> DROP everything first, then rebuild (destructive)
 * Not for production use — the client sets up via install.php.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
require_once __DIR__ . '/lib/seed_data.php';

if (in_array('--fresh', $argv, true)) {
    db()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['blocks', 'sections', 'users'] as $t) db()->exec("DROP TABLE IF EXISTS $t");
    db()->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "Dropped existing tables.\n";
}

run_schema();
echo "Schema ready.\n";

seed_user('Vindex Admin', 'admin@vindex.dk', 'Admin#2026', 'admin');
seed_user('Line Leadership', 'leader@vindex.dk', 'Leader#2026', 'leadership');
seed_user('Erik Employee', 'employee@vindex.dk', 'Employee#2026', 'employee');
echo "Users ready.\n";

$ids = seed_sections();
seed_demo_content($ids);
echo "Sections + demo content ready.\n";
echo "Done.\n";
