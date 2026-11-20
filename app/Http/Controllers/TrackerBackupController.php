<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use App\Support\TrackerMediaStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackerBackupController extends Controller
{
    public function handle(Request $request): JsonResponse|StreamedResponse
    {
        $user = (array) $request->session()->get('tracker_user');
        if (($user['role'] ?? '') !== 'admin') {
            return response()->json(['ok' => false, 'message' => 'Only administrators can manage backups.'], 403);
        }
        if (TrackerMediaStore::cloud()) return $this->handleCloud($request, $user);
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
        if (TrackerMediaStore::cloud()) return $this->createCloudBackup(false);
        $settings = $this->settings(storage_path('app/backup-settings.json'), storage_path('backups'));
        if (! $settings['enabled']) {
            return null;
        }
        $latest = $this->latest($settings['destination']);
        if ($latest && now('Asia/Taipei')->isSameDay(\Carbon\CarbonImmutable::parse($latest['createdAt'])->setTimezone('Asia/Taipei'))) {
            return null;
        }
        $archive = $this->createBackup($settings['destination']);
        \App\Support\TrackerAudit::record(['actor_id' => null, 'actor_username' => 'system', 'action' => 'backup.automatic_created', 'entity_type' => 'backup', 'summary' => 'Created an automatic project-data backup.', 'created_at' => now()]);

        return $archive;
    }
    private function cloudValue(string $key): array
    {
        $value = DB::table('application_settings')->where('setting_key', $key)->value('setting_value');
        return $value === null ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function cloudPayload(): array
    {
        $archives = $this->cloudValue('cloud_project_backups')['archives'] ?? [];
        $latest = $archives[0] ?? null;
        if ($latest) $latest['downloadUrl'] = 'api/backups.php?action=downloadLatest';
        return ['ok'=>true, 'cloud'=>true, 'enabled'=>(bool)($this->cloudValue('cloud_backup_settings')['enabled'] ?? true),
            'destination'=>'Private cloud storage', 'defaultDestination'=>'Private cloud storage', 'latest'=>$latest];
    }

    private function handleCloud(Request $request, array $user): JsonResponse|StreamedResponse
    {
        if ($request->isMethod('get')) {
            if ($request->query('action') !== 'downloadLatest') return response()->json($this->cloudPayload());
            $latest = $this->cloudPayload()['latest'];
            if (! $latest || ! TrackerMediaStore::disk()->exists($latest['key'])) return response()->json(['ok'=>false,'message'=>'No backup is available.'],404);
            return TrackerMediaStore::disk()->response($latest['key'], $latest['name'],
                ['Content-Type'=>'application/zip','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'], 'attachment');
        }
        $request->validate(['action'=>['sometimes','nullable','string']]);
        $action = (string) $request->input('action');
        if ($action === 'runManual') {
            try { $this->createCloudBackup(true); }
            catch (\DomainException $error) { return response()->json(['ok'=>false,'message'=>$error->getMessage()],409); }
            catch (\Throwable $error) { report($error); return response()->json(['ok'=>false,'message'=>'The backup could not be saved. Check storage access and available space, then try again.'],500); }
            $this->audit($user, 'backup.manual_created', 'Created a manual project-data backup in private cloud storage.');
            return response()->json($this->cloudPayload());
        }
        if ($action === 'browseDestination') return response()->json(['ok'=>false,'message'=>'Cloud backups use private storage. A Windows folder is not required.'],422);
        if (! in_array($action, ['', 'saveSettings'], true)) return response()->json(['ok'=>false,'message'=>'Unsupported backup request.'],400);
        $request->validate(['enabled'=>['required','boolean']]);
        DB::table('application_settings')->updateOrInsert(['setting_key'=>'cloud_backup_settings'],
            ['setting_value'=>json_encode(['enabled'=>$request->boolean('enabled')]), 'updated_by'=>$user['id']]);
        $this->audit($user, 'backup.settings_updated', 'Updated cloud backup settings.');
        return response()->json($this->cloudPayload());
    }

    /** Retain the latest snapshot of seven days, four weeks and three months. */
    private function retainedCloudArchives(array $archives): array
    {
        usort($archives, fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
        $groups = ['day'=>[], 'week'=>[], 'month'=>[]];
        $limits = ['day'=>7, 'week'=>4, 'month'=>3];
        $retained = [];
        foreach ($archives as $archive) {
            $keep = false;
            $date = \Carbon\CarbonImmutable::parse($archive['createdAt'])->setTimezone('Asia/Taipei');
            foreach (['day'=>'Y-m-d','week'=>'o-W','month'=>'Y-m'] as $period=>$format) {
                $key = $date->format($format);
                if (! isset($groups[$period][$key]) && count($groups[$period]) < $limits[$period]) {
                    $groups[$period][$key] = true;
                    $keep = true;
                }
            }
            if ($keep) $retained[] = $archive;
        }
        return $retained;
    }

    private function createCloudBackup(bool $manual): ?string
    {
        $locked = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', ['tracker:cloud-backup'])->locked;
        if (! $locked) {
            if ($manual) throw new \DomainException('A backup is already being created. Try again after it finishes.');
            return null;
        }
        $destination = null;
        try {
            $payload = $this->cloudPayload();
            if (! $manual && (! $payload['enabled'] || ($payload['latest'] && now('Asia/Taipei')->isSameDay(\Carbon\CarbonImmutable::parse($payload['latest']['createdAt'])->setTimezone('Asia/Taipei'))))) return null;
            $destination = storage_path('app/cloud-backup-'.bin2hex(random_bytes(8)));
            $archive = $this->createBackup($destination);
            $key = 'backups/daily/'.basename($archive);
            $stream = fopen($archive, 'rb');
            try {
                if ($stream === false || ! TrackerMediaStore::disk()->put($key, $stream, ['ContentType'=>'application/zip'])) throw new \RuntimeException('Unable to store the backup.');
            } finally { if (is_resource($stream)) fclose($stream); }
            try {
                if (! TrackerMediaStore::disk()->put($key.'.sha256', file_get_contents($archive.'.sha256'))) throw new \RuntimeException('Unable to store the checksum.');
                $previous = $this->cloudValue('cloud_project_backups')['archives'] ?? [];
                $all = array_merge([['key'=>$key,'name'=>basename($archive),'size'=>filesize($archive),'createdAt'=>now('UTC')->toIso8601String()]], $previous);
                $retained = $this->retainedCloudArchives($all);
                DB::table('application_settings')->updateOrInsert(['setting_key'=>'cloud_project_backups'], ['setting_value'=>json_encode(['archives'=>$retained],JSON_THROW_ON_ERROR)]);
            } catch (\Throwable $error) {
                try { TrackerMediaStore::disk()->delete([$key,$key.'.sha256']); } catch (\Throwable $cleanupError) { report($cleanupError); }
                throw $error;
            }
            $keys = array_column($retained, 'key');
            foreach ($all as $item) {
                if (! in_array($item['key'], $keys, true)) {
                    try { TrackerMediaStore::disk()->delete([$item['key'],$item['key'].'.sha256']); } catch (\Throwable $cleanupError) { report($cleanupError); }
                }
            }
            if (! $manual) \App\Support\TrackerAudit::record(['actor_id'=>null,'actor_username'=>'system','action'=>'backup.automatic_created','entity_type'=>'backup','summary'=>'Created an automatic cloud project-data backup.','created_at'=>now()]);
            return $key;
        } finally {
            // Only this invocation's generated, isolated staging folder is removed.
            if ($destination !== null) \Illuminate\Support\Facades\File::deleteDirectory($destination);
            DB::select('SELECT pg_advisory_unlock(hashtext(?))', ['tracker:cloud-backup']);
        }
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
        \App\Support\TrackerProjectStatus::refresh();
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
        $mediaTemps = [];
        try {
            $connection = DB::connection()->getConfig();
            if ($connection['driver'] !== 'pgsql') throw new \RuntimeException('Backups require PostgreSQL.');
            $escape = fn ($value) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $value);
            file_put_contents($defaults, implode(':', array_map($escape, [$connection['host'], $connection['port'], $connection['database'], $connection['username'], $connection['password']])).PHP_EOL);
            @chmod($defaults, 0600);
            $arguments = [config('database.pg_dump'), '--no-password', '--format=plain', '--no-owner', '--no-privileges', '--host='.$connection['host'], '--port='.$connection['port'], '--username='.$connection['username'], '--file='.$sql, '--dbname='.$connection['database']];
            // Include project workflow and audit evidence. Accounts and system tables
            // remain in the existing database when this data-only dump is restored.
            $schema = (string) DB::selectOne('SELECT current_schema() AS name')->name;
            $quote = fn (string $name) => '"'.str_replace('"', '""', $name).'"';
            $arguments[] = '--data-only';
            foreach (['projects', 'history', 'project_number_sequences', 'deletion_requests', 'deletion_request_targets', 'deletion_request_history_hidden', 'audit_log', 'import_jobs', 'import_job_changes', 'import_staging_chunks'] as $table) {
                $qualified = $quote($schema).'.'.$quote($table);
                $arguments[] = '--table='.$qualified;
                $sequence = in_array($table, ['project_number_sequences','import_jobs','import_staging_chunks','deletion_request_history_hidden'], true) ? null : DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', [$qualified, 'id'])->name;
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
            if (TrackerMediaStore::cloud()) {
                // Fetch only media referenced by this pilot's projects; no bucket-wide scan.
                DB::table('projects')->select('id', 'data')->orderBy('id')->each(function ($row) use ($zip, $work, &$mediaTemps): void {
                    $project = json_decode($row->data, true) ?: [];
                    foreach ($project['attachments'] ?? [] as $attachment) {
                        $name = basename((string) ($attachment['storedName'] ?? ''));
                        if ($name === '') continue;
                        $key = 'attachments/'.$row->id.'/'.$name;
                        $stream = TrackerMediaStore::disk()->readStream($key);
                        if (! is_resource($stream)) throw new \RuntimeException('An attachment could not be included in the backup.');
                        $temporary = $work.'/media-'.bin2hex(random_bytes(8));
                        $mediaTemps[] = $temporary;
                        $output = fopen($temporary, 'wb');
                        try {
                            if ($output === false || stream_copy_to_stream($stream, $output) === false) throw new \RuntimeException('Unable to stage a backup attachment.');
                        } finally { fclose($stream); if (is_resource($output)) fclose($output); }
                        if (! $zip->addFile($temporary, $key)) throw new \RuntimeException('Unable to add a backup attachment.');
                    }
                });
            } else {
                $this->addMediaFiles($zip, (string)config('tracker.attachments'), 'attachments');
            }
            $zip->addFromString('README.txt', "Project-data backup\r\nContains project records, history, numbering, deletion requests and decisions, import workflow, general audit evidence, an Excel export and project attachments.\r\nIt excludes application code, user accounts/passwords, profile photos and system settings.\r\nRestore database.sql into a compatible tracker schema with the original user IDs/accounts present; it is a data-only dump, not a fresh system installation. Existing project rows must be handled before restoring to avoid duplicate IDs.\r\nRestore attachments into the configured project attachment directory. The spreadsheet can also be loaded through the existing import preview.\r\n");
            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finish the backup archive.');
            }
            if (file_put_contents($archive.'.sha256', hash_file('sha256', $archive).'  '.basename($archive).PHP_EOL) === false) throw new \RuntimeException('Unable to write backup checksum.');
            $complete = true;
            if (!TrackerMediaStore::cloud()) {
            $this->createRotationCopy($archive, $destination, 'weekly', now('Asia/Taipei')->format('o-W'));
            $this->createRotationCopy($archive, $destination, 'monthly', now('Asia/Taipei')->format('Y-m'));
            }

            return $archive;
        } finally {
            if (isset($zip) && !$complete) { try { $zip->close(); } catch (\Throwable $ignored) {} }
            foreach ($mediaTemps as $temporary) @unlink($temporary);
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
            'status' => 'Operational Status', 'remarks' => 'Additional Remarks', 'materialTestingDate' => 'Material Testing Date', 'noaDueDate' => 'NOA Due Date', 'archivedAt' => 'Archived At', 'archivedBy' => 'Archived By',
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
        \App\Support\TrackerAudit::record(['actor_id' => $user['id'], 'actor_username' => $user['username'], 'action' => $action, 'entity_type' => 'backup', 'summary' => $summary, 'created_at' => now()]);
    }
}

