<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

class WipeTenantDatabasesCommand extends Command
{
    protected $signature = 'tenants:wipe-all {--force : Force the operation without confirmation}';

    protected $description = 'Wipe all tenant databases matching tenant_% pattern in MySQL';

    public function handle(): int
    {
        $this->info('Scanning MySQL for tenant databases...');

        $host = config('database.connections.mysql.host', '127.0.0.1');
        $port = config('database.connections.mysql.port', '3306');
        $username = config('database.connections.mysql.username', 'root');
        $password = config('database.connections.mysql.password', '');

        $pdo = new PDO("mysql:host={$host};port={$port}", $username, $password);
        $stmt = $pdo->query("SHOW DATABASES LIKE 'tenant_%'");
        $dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $count = count($dbs);
        if ($count === 0) {
            $this->info('No tenant databases found.');

            return self::SUCCESS;
        }

        $this->warn("Found {$count} tenant database(s). Dropping them now...");

        foreach ($dbs as $db) {
            $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
        }

        $this->info("Successfully dropped all {$count} tenant database(s).");

        return self::SUCCESS;
    }
}
