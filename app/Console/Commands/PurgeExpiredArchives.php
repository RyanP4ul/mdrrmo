<?php

namespace App\Console\Commands;

use App\Services\ArchiveService;
use Illuminate\Console\Command;

class PurgeExpiredArchives extends Command
{
    protected $signature = 'archives:purge';

    protected $description = 'Permanently delete archived records whose 30-day deletion wait period has elapsed.';

    public function handle(ArchiveService $archives): int
    {
        $result = $archives->purgeDueRecords();

        $this->info("Permanently deleted {$result['deleted']} archive(s); skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
