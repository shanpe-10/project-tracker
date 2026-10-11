<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tables = array_values(array_filter([
            'tracker_hidden_messages', 'tracker_messages', 'tracker_chat_members',
            'tracker_chat_presence', 'tracker_conversations',
        ], fn ($table) => Schema::hasTable($table)));
        if (! $tables) return;

        DB::transaction(function () use ($tables) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $grammar = DB::connection()->getQueryGrammar();
                DB::statement('LOCK TABLE '.implode(', ', array_map(fn ($table) => $grammar->wrapTable($table), $tables)).' IN ACCESS EXCLUSIVE MODE');
            }
            $archive = ['format' => 1, 'exportedAt' => now()->toISOString(), 'database' => DB::connection()->getDatabaseName(), 'tables' => []];
            foreach ($tables as $table) {
                $archive['tables'][$table] = ['columns' => Schema::getColumnListing($table), 'rows' => DB::table($table)->get()->all()];
            }
            $directory = storage_path('app/private/retired-chat');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Cannot create the private chat archive.');
            }
            $json = json_encode($archive, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $path = $directory.'/chat-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(6)).'.json';
            if (file_put_contents($path, $json, LOCK_EX) !== strlen($json) || hash_file('sha256', $path) !== hash('sha256', $json)) {
                throw new \RuntimeException('Chat archive verification failed. No tables were removed.');
            }
            foreach ($tables as $table) Schema::drop($table);
        });
    }

    public function down(): void
    {
        // Recreate the retired schema; historical data remains in the private archive.
        foreach ([
            '2026_10_07_000001_create_tracker_chat.php', '2026_10_07_000002_enhance_tracker_chat.php',
            '2026_10_07_000003_chat_history_clear.php', '2026_10_07_000004_single_message_delete.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }
};
