<?php

namespace App\Console\Commands;

use App\Support\TrackerDefaultAccounts;
use Illuminate\Console\Command;

final class InitializeSourceAccounts extends Command
{
    protected $signature = 'tracker:initialize-source-accounts';
    protected $description = 'Initialize the source edition accounts once without resetting later password changes';

    public function handle(): int
    {
        $this->info(TrackerDefaultAccounts::initialize() ? 'Source accounts initialized.' : 'Existing source accounts and passwords retained.');
        return self::SUCCESS;
    }
}
