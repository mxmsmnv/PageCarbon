<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/PageCarbon.module.php');

$assert = static function(bool $condition, string $message): void {
	if(!$condition) {
		fwrite(STDERR, $message . PHP_EOL);
		exit(1);
	}
};

$assert(strpos($source, "if(\$dialect === 'mysql')") !== false, 'MySQL-only storage query must be dialect-gated.');
$assert(substr_count(strtolower($source), 'information_schema') === 1, 'Unexpected information_schema query found.');
$assert(strpos($source, 'SELECT COUNT(*) AS row_count, MIN(created) AS oldest') !== false, 'Portable raw table statistics query is missing.');
$assert(strpos($source, "\$rawStats['bytes'] = null") !== false, 'Non-MySQL table size fallback is missing.');

echo "Database portability checks passed.\n";
