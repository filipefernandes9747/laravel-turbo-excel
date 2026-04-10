<?php

declare(strict_types=1);

require_once __DIR__.'/vendor/autoload.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;

if ($argc < 2) {
    echo "Usage: php setup_db.php <rows>\n";
    exit(1);
}

$rows = (int) $argv[1];

$app = Application::create(basePath: __DIR__);
$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite', [
    'driver' => 'sqlite',
    'database' => __DIR__.'/database.sqlite',
    'prefix' => '',
]);

$dbPath = __DIR__.'/database.sqlite';
file_put_contents($dbPath, '');

Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email');
    $table->string('status');
    $table->dateTime('created_at');
});

$chunk = 10000;
$inserted = 0;
while ($inserted < $rows) {
    $insertSize = min($chunk, $rows - $inserted);
    $data = [];
    for ($i = 1; $i <= $insertSize; $i++) {
        $idx = $inserted + $i;
        $data[] = [
            'name' => 'User '.$idx,
            'email' => "user{$idx}@example.com",
            'status' => $idx % 2 === 0 ? 'active' : 'inactive',
            'created_at' => '2023-10-01 10:00:00',
        ];
    }
    DB::table('users')->insert($data);
    $inserted += $insertSize;
}

echo "Database seeded with $rows rows.\n";
