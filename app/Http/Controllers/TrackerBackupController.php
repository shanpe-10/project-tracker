<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class TrackerBackupController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $user = (array) $request->session()->get('tracker_user');
        if (($user['role'] ?? '') !== 'admin') {
            return response()->json(['ok' => false, 'message' => 'Only administrators can manage backups.'], 403);
        }
        $settingsPath = storage_path('app/backup-settings.json');
        $default = storage_path('backups');
        $settings = $this->settings($settingsPath, $default);
        if ($request->isMethod('get')) {
            return response()->json(['ok' => true] + $settings + ['defaultDestination' => $default, 'latest' => $this->latest($settings['destination'])]);
        }
        $request->validate(['action'=>['sometimes','nullable','string']]);
        $action = (string) $request->input('action');
        if ($action === 'browseDestination') {
            return $this->browseDestination($request, $settings['destination']);
        }
        if ($action === 'runManual') {
            try {
                $archive = $this->createBackup($settings['destination']);
            } catch (\Throwable $error) {
                report($error);

                return response()->json(['ok' => false, 'message' => 'The manual backup failed: '.$error->getMessage()], 500);
            }
            $this->audit($user, 'backup.manual_created', 'Created a manual project-data backup.');

            return response()->json(['ok' => true] + $settings + ['defaultDestination' => $default, 'latest' => $this->latest($settings['destination']), 'archivePath' => $archive]);
        }
        if (!in_array($action, ['', 'saveSettings'], true)) return response()->json(['ok'=>false,'message'=>'Unsupported backup request.'],400);
        $request->validate(['destination'=>['required','string','max:4096'],'enabled'=>['required','boolean']]);
        if (str_contains($request->input('destination'), "\0")) return response()->json(['ok'=>false,'message'=>'Invalid destination folder.'],422);
        $destination = rtrim(str_replace('/', DIRECTORY_SEPARATOR, trim((string) $request->input('destination'))), '\\/');
        if (! preg_match('/^[A-Za-z]:[\\\\\/].+/', $destination) && ! str_starts_with($destination, '\\\\')) {
            return response()->json(['ok' => false, 'message' => 'Enter an absolute Windows folder path.'], 422);
        }
        if (! is_dir($destination) && ! mkdir($destination, 0770, true) && ! is_dir($destination)) {
            return response()->json(['ok' => false, 'message' => 'The destination folder could not be created.'], 422);
        }
        $probe = $destination.DIRECTORY_SEPARATOR.'.backup-write-test-'.bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'test') === false) {
            return response()->json(['ok' => false, 'message' => 'The application cannot write to this destination.'], 422);
        }
        @unlink($probe);
        $settings = ['enabled' => $request->boolean('enabled'), 'destination' => $destination, 'updatedAt' => now('UTC')->toIso8601String(), 'updatedBy' => $user['username']];
        if (! is_dir(dirname($settingsPath)) && ! mkdir(dirname($settingsPath), 0770, true) && ! is_dir(dirname($settingsPath))) {
            return response()->json(['ok' => false, 'message' => 'Backup settings storage is unavailable.'], 500);
        }
        $temporary = $settingsPath.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($temporary, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || ! rename($temporary, $settingsPath)) {
            @unlink($temporary);
            return response()->json(['ok' => false, 'message' => 'Backup settings could not be saved.'], 500);
        }
        $this->audit($user, 'backup.settings_updated', 'Updated project backup settings.');

        return response()->json(['ok' => true] + $settings + ['defaultDestination' => $default, 'latest' => $this->latest($destination)]);
    }

    /** List server folders without opening a desktop dialog from a Windows service. */
    private function browseDestination(Request $request, string $default): JsonResponse
    {
        $drives = [];
        foreach (range('A', 'Z') as $drive) {
            $root = $drive.':\\';
            if (is_dir($root)) $drives[] = $root;
        }
        $requestedPath = $request->input('path', $default);
        if (! is_string($requestedPath)) {
            return response()->json(['ok' => false, 'message' => 'Enter an absolute Windows folder path.'], 422);
        }
        $path = str_replace('/', '\\', trim($requestedPath));
        if ($path === '') $path = $drives[0] ?? '';
        if (str_contains($path, "\0") || ! preg_match('/^(?:[A-Za-z]:\\\\|\\\\\\\\[^\\\\]+\\\\[^\\\\]+)(?:.*)$/', $path)) {
            return response()->json(['ok' => false, 'message' => 'Enter an absolute Windows folder path.'], 422);
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
            return response()->json(['ok' => false, 'message' => 'This server folder does not exist or cannot be accessed.'], 422);
        }
        $directories = [];
        $truncated = false;
        try {
            foreach (new \FilesystemIterator($resolved, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if (! $entry->isDir()) continue;
                $directories[] = ['name' => $entry->getFilename(), 'path' => $entry->getPathname()];
                if (count($directories) >= 1000) { $truncated = true; break; }
            }
        } catch (\UnexpectedValueException $error) {
            return response()->json(['ok' => false, 'message' => 'The application cannot list this server folder.'], 422);
        }
        usort($directories, fn ($left, $right) => strnatcasecmp($left['name'], $right['name']));
        $parent = dirname($resolved);

        return response()->json(['ok' => true, 'selected' => false, 'path' => $resolved,
            'parent' => $parent !== $resolved ? $parent : null, 'drives' => $drives,
            'directories' => $directories, 'truncated' => $truncated]);
    }

    public function runScheduledBackup(): ?string
    {
        $settings = $this->settings(storage_path('app/backup-settings.json'), storage_path('backups'));
        if (! $settings['enabled']) {
            return null;
        }
        $latest = $this->latest($settings['destination']);
        if ($latest && now('Asia/Taipei')->isSameDay(\Carbon\CarbonImmutable::parse($latest['createdAt'])->setTimezone('Asia/Taipei'))) {
            return null;
        }
        $archive = $this->createBackup($settings['destination']);
        DB::table('audit_log')->insert(['actor_id' => null, 'actor_username' => 'system', 'action' => 'backup.automatic_created', 'entity_type' => 'backup', 'summary' => 'Created an automatic project-data backup.', 'created_at' => now()]);

        return $archive;
    }
    private function settings(string $path, string $default): array
    {
        $saved = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return ['enabled' => (bool) ($saved['enabled'] ?? true), 'destination' => trim((string) ($saved['destination'] ?? $default)) ?: $default];
    }

    private function latest(string $destination): ?array
    {
        $pattern = 'project-data-*.zip';
        $files = glob($destination.DIRECTORY_SEPARATOR.'daily'.DIRECTORY_SEPARATOR.$pattern) ?: [];
        if (! $files) {
            return null;
        } usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return ['name' => basename($files[0]), 'createdAt' => date(DATE_ATOM, filemtime($files[0])), 'size' => filesize($files[0])];
    }

    private function createBackup(string $destination): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZIP support is unavailable.');
        }
        $destination = rtrim($destination, '\\/');
        $daily = $destination.DIRECTORY_SEPARATOR.'daily';
        if (! is_dir($daily) && ! mkdir($daily, 0770, true) && ! is_dir($daily)) {
            throw new \RuntimeException('The daily backup folder could not be created.');
        }
        $stamp = now('Asia/Taipei')->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        $work = storage_path('app/backup-work-'.$stamp.'-'.bin2hex(random_bytes(3)));
        if (! mkdir($work, 0770, true) && ! is_dir($work)) {
            throw new \RuntimeException('The temporary backup folder could not be created.');
        }
        $defaults = $work.DIRECTORY_SEPARATOR.'pgpass.conf';
        $sql = $work.DIRECTORY_SEPARATOR.'database.sql';
        $workbook = $work.DIRECTORY_SEPARATOR.'all-projects.xlsx';
        $archive = $daily.DIRECTORY_SEPARATOR.'project-data-'.$stamp.'.zip';
        $complete = false;
        try {
            $connection = DB::connection()->getConfig();
            if ($connection['driver'] !== 'pgsql') throw new \RuntimeException('Backups require PostgreSQL.');
            $escape = fn ($value) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $value);
            file_put_contents($defaults, implode(':', array_map($escape, [$connection['host'], $connection['port'], $connection['database'], $connection['username'], $connection['password']])).PHP_EOL);
            @chmod($defaults, 0600);
            $arguments = [config('database.pg_dump'), '--no-password', '--format=plain', '--no-owner', '--no-privileges', '--host='.$connection['host'], '--port='.$connection['port'], '--username='.$connection['username'], '--file='.$sql, '--dbname='.$connection['database']];
            // Dump only project rows/history/numbering. Accounts and system tables
            // remain in the existing database when this data-only dump is restored.
            $schema = (string) DB::selectOne('SELECT current_schema() AS name')->name;
            $quote = fn (string $name) => '"'.str_replace('"', '""', $name).'"';
            $arguments[] = '--data-only';
            foreach (['projects', 'history', 'project_number_sequences'] as $table) {
                $qualified = $quote($schema).'.'.$quote($table);
                $arguments[] = '--table='.$qualified;
                $sequence = $table === 'project_number_sequences' ? null : DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', [$qualified, 'id'])->name;
                if ($sequence) $arguments[] = '--table='.$sequence;
            }
            $process = new Process($arguments, null, $this->nativeProcessEnvironment(['PGPASSFILE' => $defaults, 'PGPASSWORD' => false, 'PGSSLMODE' => $connection['sslmode'] ?? 'prefer']));
            $process->setTimeout(300);
            $process->mustRun();
            $zip = new \ZipArchive;
            if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create backup archive.');
            }
            $zip->addFile($sql, 'database.sql');
            $this->createProjectsWorkbook($workbook);
            $zip->addFile($workbook, 'exports/all-projects.xlsx');
            $this->addMediaFiles($zip, (string)config('tracker.attachments'), 'attachments');
            $zip->addFromString('README.txt', "Project-data backup\r\nContains project records, history, numbering, an Excel export and project attachments.\r\nIt excludes application code, user accounts/passwords, profile photos and system settings.\r\nRestore database.sql into a compatible tracker schema with the original user IDs/accounts present; it is a data-only dump, not a fresh system installation. Existing project rows must be handled before restoring to avoid duplicate IDs.\r\nRestore attachments into the configured project attachment directory. The spreadsheet can also be loaded through the existing import preview.\r\n");
            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finish the backup archive.');
            }
            if (file_put_contents($archive.'.sha256', hash_file('sha256', $archive).'  '.basename($archive).PHP_EOL) === false) throw new \RuntimeException('Unable to write backup checksum.');
            $complete = true;
            $this->createRotationCopy($archive, $destination, 'weekly', now('Asia/Taipei')->format('o-W'));
            $this->createRotationCopy($archive, $destination, 'monthly', now('Asia/Taipei')->format('Y-m'));

            return $archive;
        } finally {
            @unlink($defaults);
            @unlink($sql);
            @unlink($workbook);
            @rmdir($work);
            if (!$complete) {
                if (isset($zip)) { try { $zip->close(); } catch (\Throwable $ignored) {} }
                @unlink($archive);
                @unlink($archive.'.sha256');
            }
        }
    }

    private function addMediaFiles(\ZipArchive $zip, string $directory, string $prefix): void
    {
        if (!is_dir($directory)) return;
        $root = rtrim($directory,'\\/');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $relative = str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            if (!$zip->addFile($file->getPathname(),$prefix.'/'.$relative)) throw new \RuntimeException('Unable to add uploaded media to backup.');
        }
    }

    private function createProjectsWorkbook(string $path): void
    {
        $columns = [
            'projectNo' => 'Project No.', 'biddingDate' => 'Date of Bidding', 'title' => 'Project Title', 'approvedBudget' => 'ABC',
            'bidAmount' => 'Bid Amount', 'winningBidder' => 'Winning Bidder', 'sourceOfFund' => 'Source of Fund',
            'prNumber' => 'PR Number', 'prDate' => 'PR Date', 'obre' => 'OBRE', 'resolutionNumber' => 'Resolution Number',
            'resolutionDate' => 'Resolution Date', 'noticePostQual' => 'Notice of Post Qualification', 'noa' => 'Notice of Award',
            'performanceBond' => 'Performance Bond', 'contractRef' => 'Contract Date', 'contractDuration' => 'Contract Duration',
            'ntpDate' => 'Notice to Proceed', 'dole' => 'DOLE Clearance', 'contractEndDate' => 'Contract End Date',
            'preInspection' => 'Pre Inspection', 'finalInspection' => 'Final Inspection', 'swa' => 'SWA', 'ldAmount' => 'LD Amount',
            'ldNoOfDays' => 'LD Days', 'ldNewCompletionDate' => 'Extended Completion Date',
            'ldActualDuration' => 'Actual Construction Duration', 'completion' => 'Completion Date',
            'status' => 'Operational Status', 'remarks' => 'Additional Remarks', 'materialTestingDate' => 'Material Testing Date', 'archivedAt' => 'Archived At', 'archivedBy' => 'Archived By',
        ];
        $rows = [array_values($columns)];
        DB::table('projects')->select('data', 'archived_at')
            ->orderByRaw(\App\Support\TrackerSql::order())
            ->each(function ($row) use (&$rows, $columns): void {
                $record = json_decode($row->data, true) ?: [];
                if ($row->archived_at && empty($record['archivedAt'])) $record['archivedAt'] = (string) $row->archived_at;
                $rows[] = array_map(function ($key) use ($record): string {
                    $value = $record[$key] ?? '';
                    return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                }, array_keys($columns));
            });
        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $columnIndex => $value) {
                $reference = $this->spreadsheetColumn($columnIndex + 1).($rowIndex + 1);
                $safe = preg_replace('/[^\P{C}\t\r\n]/u', '', $value) ?? '';
                $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($safe, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }
        $xlsx = new \ZipArchive;
        if ($xlsx->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Unable to create the projects Excel workbook.');
        $xlsx->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $xlsx->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $xlsx->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="All Projects" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $xlsx->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xlsx->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>'.$sheetRows.'</sheetData><autoFilter ref="A1:'.$this->spreadsheetColumn(count($columns)).count($rows).'"/></worksheet>');
        if (! $xlsx->close()) throw new \RuntimeException('Unable to finish the projects Excel workbook.');
    }

    private function spreadsheetColumn(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + ($number % 26)).$column;
            $number = intdiv($number, 26);
        }
        return $column;
    }
    private function createRotationCopy(string $archive, string $destination, string $period, string $key): void
    {
        $directory = $destination.DIRECTORY_SEPARATOR.$period;
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new \RuntimeException("The $period backup folder could not be created.");
        }
        $prefix = 'project-data-';
        if (glob($directory.DIRECTORY_SEPARATOR.$prefix.$key.'-*.zip')) {
            return;
        }
        $copy = $directory.DIRECTORY_SEPARATOR.$prefix.$key.'-'.basename($archive);
        if (! copy($archive, $copy)) {
            throw new \RuntimeException("The $period backup copy could not be created.");
        }
        file_put_contents($copy.'.sha256', hash_file('sha256', $copy).'  '.basename($copy).PHP_EOL);
    }
    private function nativeProcessEnvironment(array $environment = []): array
    {
        // HTTP request environments may omit this Windows system variable.
        // Native crypto used by pg_dump requires it to generate secure random keys.
        if (DIRECTORY_SEPARATOR === '\\') {
            $systemRoot = getenv('SystemRoot') ?: getenv('SYSTEMROOT');
            if (is_string($systemRoot) && $systemRoot !== '') $environment['SystemRoot'] = $systemRoot;
        }
        return $environment;
    }

    private function audit(array $user, string $action, string $summary): void
    {
        DB::table('audit_log')->insert(['actor_id' => $user['id'], 'actor_username' => $user['username'], 'action' => $action, 'entity_type' => 'backup', 'summary' => $summary, 'created_at' => now()]);
    }
}

