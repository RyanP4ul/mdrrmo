<?php

namespace App\Console\Commands;

use App\Services\AutomaticDispatch;
use Illuminate\Console\Command;

class AutoDispatchReports extends Command
{
    protected $signature = 'mobile:auto-dispatch';

    protected $description = 'Retry pending emergency reports under the admin-controlled automatic dispatch policy';

    public function handle(AutomaticDispatch $dispatch): int
    {
        $this->info('Dispatched '.$dispatch->drain().' report(s).');

        return self::SUCCESS;
    }
}
