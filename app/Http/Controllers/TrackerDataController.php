<?php

namespace App\Http\Controllers;

use App\Support\FormattedProjectExport;
use App\Support\TrackerProjectFields;
use App\Support\TrackerProjectRevision;
use App\Support\TrackerMilestones;
use App\Support\TrackerHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackerDataController extends Controller
{
    private const PROJECT_DATE_FIELDS = [
        'biddingDate', 'prDate', 'obre', 'resolutionDate', 'noticePostQual',
        'noa', 'noaDueDate', 'performanceBond', 'contractRef', 'ntpDate', 'dole',
        'contractEndDate', 'preInspection', 'materialTestingDate', 'materialRequestDate', 'finalInspection', 'swa',
        'ldNewCompletionDate', 'completion',
    ];

    public static function validProjectDate(mixed $value): bool
    {
        return TrackerMilestones::date($value) !== null;
    }

    public function get(Request $request): JsonResponse|StreamedResponse
    {
        $request->validate([
            'action'=>['sometimes','nullable','string'], 'page'=>['sometimes','integer','min:1'],
            'pageSize'=>['sometimes','integer','min:1'], 'projectId'=>['sometimes','integer','min:1'],
            'search'=>['sometimes','nullable','string','max:2000'], 'brgy'=>['sometimes','nullable','string','max:2000'],
            'status'=>['sometimes','nullable','string','max:100'], 'projectNo'=>['sometimes','nullable','string','max:60'],
            'lifecycle'=>['sometimes','nullable','string'], 'dateField'=>['sometimes','nullable','string'],
            'direction'=>['sometimes','nullable','string'], 'format'=>['sometimes','nullable','string'],
            'month'=>['sometimes','nullable','integer'], 'day'=>['sometimes','nullable','integer'],
            'year'=>['sometimes','nullable','integer'], 'completionYear'=>['sometimes','nullable','integer'],
            'alertPage'=>['sometimes','integer','min:1'], 'alertPageSize'=>['sometimes','integer','min:1'],
            'alertSearch'=>['sometimes','nullable','string','max:2000'],
        ]);
        \App\Support\TrackerProjectStatus::refresh();
        $action = (string) $request->query('action', '');

        $response = match ($action) {
            'listProjects' => $this->listProjects($request),
            'projectHistory' => $this->projectHistory($request),
            'projectVersion' => response()->json(['ok' => true, 'version' => $this->projectVersion()]),
            'nextProjectNumber' => response()->json(['ok' => true] + \App\Support\TrackerProjectNumber::preview()),
            'dashboard' => $this->dashboard($request),
            'notifications' => $this->notifications(),
            'projectAlerts' => $this->projectAlertDetails($request),
            default => $this->bootstrapData(),
        };
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function projectVersion(): string
    {
        return hash('sha256', json_encode([
            now('Asia/Manila')->format('Y-m-d'),
            DB::table('audit_log')->whereIn('entity_type', ['project', 'projects', 'import_job'])->max('id'),
            DB::table('projects')->count(),
            \App\Support\TrackerHolidays::state()['revision'],
            $this->timelineSettings(),
        ]));
    }

    private function bootstrapData(): JsonResponse
    {
        // Capture the marker BEFORE reading records. A later commit must not be
        // acknowledged until the browser has actually loaded its data.
        $version = $this->projectVersion();
        return response()->json(['ok' => true, 'version' => $version,
            'projects' => $this->records('projects'), 'history' => [],
            'timelineSettings' => $this->timelineSettings(),
            'holidayCalendar' => \App\Support\TrackerHolidays::calendar()]);
    }

    public function post(Request $request): JsonResponse
    {
        $request->validate([
            'action'=>['required','string','max:80'], 'id'=>['sometimes','required','integer','min:1'],
            'settings'=>['sometimes','array'], 'batchId'=>['sometimes','required','string','regex:/^[A-Za-z0-9_-]{12,64}$/D'],
            'store'=>['sometimes','required','string','in:projects,history'],
            'archived'=>['sometimes','required','boolean'], 'archive'=>['sometimes','boolean'], 'isImport'=>['sometimes','boolean'],
        ]);
        $action = (string) $request->input('action', '');

        return match ($action) {
            'saveTimelineRecord' => $this->saveTimelineRecord($request),
            'saveProject', 'saveHistory' => DB::transaction(fn () => $this->saveRecord($request, $action)),
            'deleteProject', 'deleteHistory' => $this->deleteRecord($request, $action),
            'setProjectArchived' => $this->setArchived($request),
            'saveTimelineSettings' => $this->saveTimelineSettings($request),
            'clearStore' => $this->clearStore($request),
            'bulkSaveProjects' => $this->bulkSave($request),
            'completeImport' => $this->completeImport($request),
            'rollbackImport' => $this->rollbackImport($request),
            default => response()->json(['ok' => false, 'message' => 'Unsupported data request.'], 400),
        };
    }

    private function records(string $table): array
    {
        $order = $table === 'projects' ? \App\Support\TrackerSql::order() : 'id';

        return DB::table($table)->select('id', 'data')->orderByRaw($order)->get()->map(function ($row) {
            $record = json_decode($row->data, true) ?: [];
            $record['id'] = (int) $row->id;

            return $record;
        })->all();
    }

    private function listProjects(Request $request): JsonResponse|StreamedResponse
    {
        $pageSize = max(10, min(100, (int) $request->query('pageSize', 25)));
        $query = DB::table('projects');
        $lifecycle = strtolower((string) $request->query('lifecycle', 'all'));
        if ($lifecycle === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }
        if (in_array($lifecycle, ['completed', 'released'], true)) {
            $query->whereRaw("LOWER(TRIM(COALESCE(project_status, ''))) = ?", [$lifecycle]);
        }
        if ($lifecycle === 'active') {
            $query->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(project_status, '')))"), ['completed', 'released']);
        }
        if (($status = trim((string) $request->query('status', ''))) !== '' && $status !== 'All') {
            $query->where('project_status', $status);
        }
        if (($number = trim((string) $request->query('projectNo', ''))) !== '') {
            $query->where('project_no', $number);
        }
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $query->where(function ($q) use ($search) {
                foreach (['project_no', 'title', 'winning_bidder', 'pr_number', 'source_of_fund'] as $i => $column) {
                    if (DB::connection()->getDriverName() === 'pgsql') $column .= ' COLLATE "und-x-icu"';
                    $i ? $q->orWhereRaw("LOWER({$column}) LIKE ?", [mb_strtolower("%$search%")]) : $q->whereRaw("LOWER({$column}) LIKE ?", [mb_strtolower("%$search%")]);
                }
            });
        }
        if (($barangay = trim((string) $request->query('brgy', ''))) !== '') {
            $query->whereRaw(\App\Support\TrackerSql::barangay(), [$this->barangayFilterPattern($barangay)]);
        }
        $dateFields = [
            'biddingDate' => 'bidding_date', 'completion' => 'completion_date', 'noa' => 'noa_date', 'ntpDate' => 'ntp_date',
            'prDate' => \App\Support\TrackerSql::date('prDate'),
            'resolutionDate' => \App\Support\TrackerSql::date('resolutionDate'),
            'noticePostQual' => \App\Support\TrackerSql::date('noticePostQual'),
            'performanceBond' => \App\Support\TrackerSql::date('performanceBond'),
            'contractRef' => \App\Support\TrackerSql::date('contractRef'),
            'dole' => \App\Support\TrackerSql::date('dole'),
            'contractEndDate' => \App\Support\TrackerSql::date('contractEndDate'),
            'preInspection' => \App\Support\TrackerSql::date('preInspection'),
            'noaDueDate' => \App\Support\TrackerSql::date('noaDueDate'),
            'materialTestingDate' => \App\Support\TrackerSql::date('materialTestingDate'),
            'finalInspection' => \App\Support\TrackerSql::date('finalInspection'),
            'ldNewCompletionDate' => \App\Support\TrackerSql::date('ldNewCompletionDate'),
        ];
        $selectedDate = (string) $request->query('dateField', '');
        $expressions = isset($dateFields[$selectedDate]) ? [$dateFields[$selectedDate]] : array_values($dateFields);
        $month = (int) $request->query('month');
        $day = (int) $request->query('day');
        $year = (int) $request->query('year');
        if (($month >= 1 && $month <= 12) || ($day >= 1 && $day <= 31) || ($year >= 1900 && $year <= 9999)) {
            $query->where(function ($outer) use ($expressions, $month, $day, $year) {
                foreach ($expressions as $expression) {
                    $outer->orWhere(function ($inner) use ($expression, $month, $day, $year) {
                        if ($month >= 1 && $month <= 12) {
                            $inner->whereRaw(\App\Support\TrackerSql::datePart('month', $expression).' = ?', [$month]);
                        }
                        if ($day >= 1 && $day <= 31) {
                            $inner->whereRaw(\App\Support\TrackerSql::datePart('day', $expression).' = ?', [$day]);
                        }
                        if ($year >= 1900 && $year <= 9999) {
                            $inner->whereRaw(\App\Support\TrackerSql::datePart('year', $expression).' = ?', [$year]);
                        }
                    });
                }
            });
        }
        // Apply the same alert selection to pagination and every export format.
        if ($lifecycle === 'alerts') {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Taipei'));
            $settings = $this->timelineSettings();
            $ids = (clone $query)->select('id','data')->get()->filter(fn ($row) => $this->projectAlerts(json_decode($row->data,true) ?: [], $settings, $today) !== [])->pluck('id')->all();
            $query->whereIn('id',$ids);
        }
        $direction = strtolower((string) $request->query('direction')) === 'desc' ? 'DESC' : 'ASC';
        $order = \App\Support\TrackerSql::order($direction);
        if (in_array($request->query('format'), ['csv', 'xlsx'], true)) {
            $user = $this->user($request);
            if (empty($user['canExport'])) {
                return response()->json(['ok' => false, 'message' => 'You do not have permission to export projects.'], 403);
            }
            $rows = $query->orderByRaw($order)->pluck('data');
            $columns = TrackerProjectFields::columns();

            if ($request->query('format') === 'xlsx') {
                $records = $rows->map(fn ($json) => TrackerProjectRevision::exportRecord(json_decode($json, true) ?: []));
                $path = FormattedProjectExport::create($records, public_path('templates/project-export-template.xlsx'));

                return response()->streamDownload(function () use ($path) {
                    try {
                        readfile($path);
                    } finally {
                        @unlink($path);
                    }
                }, 'LGU_Project_Tracker_'.date('Y-m-d').'.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ]);
            }

            return response()->streamDownload(function () use ($rows, $columns) {
                $out = fopen('php://output', 'wb');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_values($columns), ',', '"', '');
                foreach ($rows as $json) {
                    $record = TrackerProjectRevision::exportRecord(json_decode($json, true) ?: []);
                    fputcsv($out, array_map(fn ($key) => $this->csvText(TrackerProjectFields::isNotApplicable($record) && str_starts_with($key,'ld') ? TrackerProjectFields::display($record,$key) : ($record[$key] ?? null)), array_keys($columns)), ',', '"', '');
                } fclose($out);
            }, 'LGU_Project_Tracker_'.date('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
        }
        $paginator = $query->orderByRaw($order)->paginate($pageSize);
        $projects = collect($paginator->items())->map(function ($row) {
            $record = json_decode($row->data, true) ?: [];
            $record['id'] = (int) $row->id;
            if ($row->archived_at && empty($record['archivedAt'])) {
                $record['archivedAt'] = $row->archived_at;
            }

            return $record;
        });

        return response()->json(['ok' => true, 'projects' => $projects, 'page' => $paginator->currentPage(), 'pageSize' => $pageSize, 'totalRecords' => $paginator->total(), 'totalPages' => $paginator->lastPage()]);
    }

    private function projectHistory(Request $request): JsonResponse
    {
        $id = (int) $request->query('projectId');
        $pageSize = max(10, min(100, (int) $request->query('pageSize', 25)));
        if ($id < 1) {
            return response()->json(['ok' => false, 'message' => 'Invalid project id.'], 422);
        }
        $ordered = DB::table('history as h')->where('h.project_id', $id)
            ->orderByDesc('h.event_at')->orderByDesc('h.id');
        $p = (clone $ordered)
            ->leftJoin('users as u', 'u.id', '=', 'h.updated_by')
            ->select('h.*', 'u.id as actor_id', 'u.username as actor_username', 'u.full_name as actor_full_name')->paginate($pageSize);
        $history = [];
        $newerSnapshot = null;
        foreach ($p->items() as $index => $row) {
            $record = TrackerHistory::snapshot($row->data) ?? [];
            $record['id'] = (int) $row->id;
            // New logs retain the account at the time of the action. Older logs use their own actor.
            if (! is_array($record['actor'] ?? null)) {
                $record['actor'] = $row->actor_id ? TrackerHistory::actor([
                    'id' => $row->actor_id, 'username' => $row->actor_username, 'fullName' => $row->actor_full_name,
                ]) : ['id' => null, 'username' => is_string($record['updatedBy'] ?? null) ? $record['updatedBy'] : '', 'fullName' => ''];
            }

            $before = TrackerHistory::snapshot($record['changes'] ?? null);
            if (TrackerHistory::isLegacyEdit($record, $before)) {
                $source = 'next';
                if ($index === 0 && $p->currentPage() === 1) {
                    $newerSnapshot = TrackerHistory::snapshot(DB::table('projects')->where('id', $id)->value('data'));
                    $source = 'current';
                } elseif ($index === 0) {
                    $neighbor = TrackerHistory::snapshot($ordered->offset($p->firstItem() - 2)->value('h.data'));
                    $newerSnapshot = TrackerHistory::snapshot($neighbor['changes'] ?? null);
                }
                if (TrackerHistory::matchesLegacyEdit($record, $before, $newerSnapshot)) {
                    $record['after'] = $newerSnapshot;
                    $record['comparisonSource'] = $source;
                }
            }
            $history[] = $record;
            $newerSnapshot = $before;
        }

        return response()->json(['ok' => true, 'history' => $history, 'page' => $p->currentPage(), 'pageSize' => $pageSize, 'totalRecords' => $p->total(), 'totalPages' => $p->lastPage()]);
    }

    private function groupAlerts(array $alerts): array
    {
        $groups = [];
        foreach ($alerts as $alert) {
            $id = $alert['project']['id'];
            $groups[$id] ??= ['project'=>$alert['project'],'alerts'=>[]];
            $groups[$id]['alerts'][] = $alert;
        }
        return array_values($groups);
    }

    private function notifications(): JsonResponse
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Taipei'));
        $settings = $this->timelineSettings();
        $alerts = [];
        foreach (DB::table('projects')->whereNull('archived_at')->select('id','data')->get() as $row) {
            $project = json_decode($row->data,true) ?: [];
            $project['id'] = (int) $row->id;
            array_push($alerts,...$this->projectAlerts($project,$settings,$today));
        }
        return response()->json(['ok'=>true,'groups'=>$this->groupAlerts($alerts),'alertTotal'=>count($alerts)]);
    }

    private function projectAlertDetails(Request $request): JsonResponse
    {
        $request->validate(['projectId'=>['required','integer','min:1']]);
        $row = DB::table('projects')->where('id',$request->query('projectId'))->first();
        if (! $row) return response()->json(['ok'=>false,'message'=>'Project not found.'],404);
        $project = json_decode($row->data,true) ?: [];
        $project['id'] = (int) $row->id;
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Taipei'));
        $alerts = array_map(fn ($alert)=>array_diff_key($alert,['project'=>true])+['status'=>'Active'], $this->projectAlerts($project,$this->timelineSettings(),$today));
        $dates = TrackerMilestones::dueDates($project, $this->timelineSettings());
        foreach (TrackerMilestones::automaticResolutions($project,$today) as $resolved) {
            $due = match ($resolved['type']) {
                'pre-inspection'=>$dates['preInspectionDueDate'], 'ntp-due'=>$dates['ntpDueDate'],
                'contract-end'=>$dates['contractEndReminderDate'], 'material-test'=>$dates['materialTestingDueDate'], 'final-inspection'=>$dates['finalInspectionDueDate'],
                'award-overdue','award-close'=>$this->projectDate($project, 'completion') ? TrackerMilestones::weekday($this->projectDate($project, 'completion')->modify('+1 year'))->format('Y-m-d') : null,
                'noa-due'=>$dates['noaDueDate'], 'noa-active'=>$project['noa'] ?? null,
                default=>null,
            };
            $alerts[] = ['type'=>$resolved['type'],'label'=>$this->alertLabel($resolved['type']),'status'=>'Resolved','doneDate'=>$resolved['doneDate'],'dueDate'=>$due];
        }
        foreach ((array)($project['timelineAlertsCompleted'] ?? []) as $type) {
            $automatic = TrackerMilestones::automaticResolutions($project,$today);
            if (array_filter($automatic,fn ($r)=>in_array($type,$r['aliases'],true))) continue;
            $alerts[] = ['type'=>$type,'label'=>$this->alertLabel($type),'status'=>'Resolved','doneDate'=>$project['timelineMarkedDoneDate'] ?? null];
        }
        if (! $row->archived_at && empty($project['timelineCompletedMarked']) && ! in_array(strtolower($project['status'] ?? ''),['completed','released'],true)) {
            foreach (['noa-due'=>'noaDueDate','pre-inspection'=>'preInspectionDueDate','ntp-due'=>'ntpDueDate','material-test'=>'materialTestingDueDate','final-inspection'=>'finalInspectionDueDate','contract-end'=>'contractEndReminderDate'] as $type=>$field) {
                if ($dates[$field] && ! array_filter($alerts,fn ($a)=>str_starts_with($a['type'],$type) || ($type==='material-test' && str_starts_with($a['type'],'material')))) {
                    $alerts[] = ['type'=>$type,'label'=>$this->alertLabel($type),'status'=>'Upcoming','dueDate'=>$dates[$field]];
                }
            }
        }
        return response()->json(['ok'=>true,'project'=>$project,'alerts'=>$alerts]);
    }

    private function alertLabel(string $type): string
    {
        return match (true) {
            str_starts_with($type,'noa')=>'Notice of Award', str_starts_with($type,'ntp')=>'Notice to Proceed',
            str_starts_with($type,'pre-inspection')=>'Pre-Inspection', str_starts_with($type,'final-inspection')=>'Final Inspection',
            str_starts_with($type,'contract-end')=>'Contract End Follow-up', str_starts_with($type,'material')=>'Material Testing', str_starts_with($type,'award')=>'SWA / Completion',
            default=>ucwords(str_replace('-',' ',$type)),
        };
    }

    private function dashboard(Request $request): JsonResponse
    {
        $projects = DB::table('projects')->whereNull('archived_at')->select('id', 'data', 'project_status', 'updated_at')->get();
        $statuses = ['Pending', 'On-going Bidding', 'Pre-inspection', 'Final-inspection', 'Completed', 'Released'];
        $counts = array_fill_keys($statuses, 0);
        $completion = array_fill(0, 12, 0);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Taipei'));
        $statusYear = (int) $request->query('year', $today->format('Y'));
        $statusMonth = (int) $request->query('month', 0);
        $completionYear = (int) $request->query('completionYear', $statusYear);
        $alertPage = max(1, (int) $request->query('alertPage', 1));
        $alertPageSize = max(10, min(100, (int) $request->query('alertPageSize', 25)));
        $alertSearch = mb_strtolower(trim((string) $request->query('alertSearch', '')));
        $statusYears = [];
        $completionYears = [];
        $alerts = [];
        $settings = $this->timelineSettings();
        $recent = $projects->sortByDesc('updated_at')->take(5)->map(function ($row) {
            $r = json_decode($row->data, true) ?: [];
            $r['id'] = (int) $row->id;

            return $r;
        })->values();
        foreach ($projects as $row) {
            $record = json_decode($row->data, true) ?: [];
            $record['id'] = (int) $row->id;
            $status = (string) ($record['status'] ?? 'Pending');
            // Imported and legacy records can have a status without any milestone
            // date. Use the last update as their dashboard status date so they are
            // still represented in the selected period instead of disappearing.
            $statusDate = $this->projectStatusDate($record)
                ?: (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $row->updated_at, $updated) ? $updated[0] : null);
            if ($statusDate) {
                $statusYears[substr($statusDate, 0, 4)] = true;
            }
            if (isset($counts[$status]) && $statusDate && (int) substr($statusDate, 0, 4) === $statusYear && (! $statusMonth || (int) substr($statusDate, 5, 2) === $statusMonth)) {
                $counts[$status]++;
            }
            if (strtolower($status) === 'completed' && preg_match('/^(\d{4})-(\d{2})-/', (string) ($record['completion'] ?? ''), $m)) {
                $completionYears[$m[1]] = true;
                if ((int) $m[1] === $completionYear) {
                    if (self::validProjectDate($record['completion'] ?? null)) $completion[(int) $m[2] - 1]++;
                }
            }
            $projectAlerts = $this->projectAlerts($record, $settings, $today);
            $haystack = mb_strtolower(implode(' ', [$record['projectNo'] ?? '', $record['title'] ?? '', $record['winningBidder'] ?? '',
                ...array_column($projectAlerts,'label'), ...array_column($projectAlerts,'type'), ...array_column($projectAlerts,'dueDate')]));
            if ($alertSearch === '' || str_contains($haystack, $alertSearch)) array_push($alerts, ...$projectAlerts);
        }
        $pending = $projects->filter(fn ($p) => strtolower(trim((string) $p->project_status)) === 'pending')->count();
        $completed = $projects->filter(fn ($p) => strtolower(trim((string) $p->project_status)) === 'completed')->count();
        $totalAlerts = count($alerts);
        $groups = $this->groupAlerts($alerts);
        $projectTotal = count($groups);
        $alertPages = max(1, (int) ceil($projectTotal / $alertPageSize));
        $alertPage = min($alertPage, $alertPages);
        $groups = array_slice($groups, ($alertPage - 1) * $alertPageSize, $alertPageSize);
        $alerts = array_merge([], ...array_column($groups,'alerts'));
        $statusYears = array_keys($statusYears);
        $completionYears = array_keys($completionYears);
        rsort($statusYears, SORT_NUMERIC);
        rsort($completionYears, SORT_NUMERIC);

        return response()->json(['ok' => true, 'metrics' => ['total' => $projects->count(), 'pending' => $pending, 'completed' => $completed, 'alerts' => $totalAlerts], 'alerts' => $alerts, 'alertGroups'=>$groups, 'alertProjectTotal'=>$projectTotal, 'alertPage' => $alertPage, 'alertPageSize' => $alertPageSize, 'alertTotal' => $totalAlerts, 'alertTotalPages' => $alertPages, 'statusCounts' => $counts, 'statusYears' => $statusYears, 'completionCounts' => $completion, 'completionYears' => $completionYears, 'recent' => $recent]);
    }

    private function saveRecord(Request $request, string $action): JsonResponse
    {
        $user = $this->user($request);
        $record = $request->input('record');
        if (! is_array($record)) {
            return response()->json(['ok' => false, 'message' => 'Invalid record.'], 422);
        }
        $project = $action === 'saveProject';
        $id = is_numeric($record['id'] ?? null) ? (int) $record['id'] : 0;
        if ($project) {
            $canSave = $id
                ? (! empty($user['canEdit']) || ! empty($user['canResolveAlerts']))
                : ($request->boolean('isImport') ? ! empty($user['canImport']) : ! empty($user['canCreate']));
            if (! $canSave) {
                return response()->json(['ok' => false, 'message' => 'You do not have permission to save projects.'], 403);
            }
        }
        if (! $project && empty($user['canEdit']) && empty($user['canResolveAlerts'])) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to add project history.'], 403);
        }
        if (!$project && $id) return response()->json(['ok'=>false,'message'=>'Project Logs are permanent. Add a new correction entry instead.'],422);
        if ($project) \App\Support\TrackerProjectInput::validate($record);
        else {
            $request->validate([
                'record.id'=>['sometimes','nullable','integer','min:1'],
                'record.projectId'=>['required','integer','min:1'],
                'record.timestamp'=>['sometimes','nullable','string','date'],
                'record.editedAt'=>['sometimes','nullable','string','date'],
                'record.updatedAt'=>['sometimes','nullable','string','max:100'],
                'record.action'=>['sometimes','nullable','string','max:80'],
                'record.details'=>['sometimes','nullable','string','max:2000'],
                'record.changes'=>['sometimes','nullable','string'],
            ]);
            // Serialize with project deletion so new history cannot become orphaned.
            if (! DB::table('projects')->where('id', $record['projectId'])->lockForUpdate()->exists()) {
                return response()->json(['ok'=>false,'message'=>'History requires an existing project.'],422);
            }
        }
        if ($project && trim((string) ($record['title'] ?? '')) === '') {
            return response()->json(['ok' => false, 'message' => 'Project title is required.'], 422);
        }
        if ($project && ! $id && ! $request->boolean('isImport')) {
            $biddingDate = (string) ($record['biddingDate'] ?? '');

            $budget = preg_replace('/[₱$,\s]/u', '', (string) ($record['approvedBudget'] ?? ''));
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $budget)) {
                return response()->json(['ok' => false, 'message' => 'Approved Budget is required and must be a non-negative amount with at most two decimal places.'], 422);
            }
        }
        if ($project && ($record['statusMode'] ?? '') !== 'manual' && strtolower(trim((string) ($record['status'] ?? ''))) === 'completed' && ! self::validProjectDate($record['completion'] ?? null)) {
            $previous = $id ? json_decode((string) DB::table('projects')->where('id', $id)->value('data'), true) : null;
            $legacyUndated = is_array($previous)
                && strtolower(trim((string) ($previous['status'] ?? ''))) === 'completed'
                && trim((string) ($previous['completion'] ?? '')) === ''
                && trim((string) ($record['completion'] ?? '')) === '';
            if (! $legacyUndated) {
                return response()->json(['ok' => false, 'message' => 'Completed projects require a valid Completion Date.'], 422);
            }
        }
        if ($project && $id && empty($user['canEdit'])) {
            $stored = DB::table('projects')->where('id', $id)->value('data');
            if ($stored === null) return response()->json(['ok'=>false,'message'=>'Project not found.'],404);
            $before = json_decode($stored,true) ?: [];
            $allowed = ['id','updatedAt','updatedBy','timelineAlertsCompleted','timelineCompletedMarked','timelineMarkedDoneDate','timelineUnmarkedType','timelineUnmarkedAt'];
            $businessBefore = array_diff_key($before,array_flip($allowed));
            $businessAfter = array_diff_key($record,array_flip($allowed));
            if ($businessBefore != $businessAfter) return response()->json(['ok'=>false,'message'=>'Alert permission only allows restoring historical alerts.'],403);
        }
        if ($project) {
            $previous = $id ? json_decode((string)DB::table('projects')->where('id',$id)->value('data'), true) : [];
            \App\Support\TrackerProjectInput::validateAlertPermission($record,is_array($previous) ? $previous : [],!empty($user['canResolveAlerts']));
            \App\Support\TrackerProjectInput::validateDates($record, is_array($previous) ? $previous : []);
            \App\Support\TrackerProjectInput::validateManualResolution($record, is_array($previous) ? $previous : []);
            if (!$id) unset($record['attachments'], $record['archivedAt'], $record['archivedBy']);
        }
        $clientUpdatedAt = (string) ($record['updatedAt'] ?? '');
        unset($record['id']);
        $record['updatedBy'] = $user['username'];
        if ($project) unset($record['actor']);
        else $record['actor'] = TrackerHistory::actor($user);
        $record['updatedAt'] = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
        $json = json_encode($record, JSON_UNESCAPED_UNICODE);
        if ($project) {
            $record = \App\Support\TrackerProjectInput::normalize($record);
            $json = json_encode($record, JSON_UNESCAPED_UNICODE);
            $columns = $this->projectColumns($record);
            if ($id) {
                $stored = DB::table('projects')->where('id', $id)->value('data');
                if ($stored === null) {
                    return response()->json(['ok' => false, 'message' => 'This record no longer exists. Refresh and try again.'], 409);
                }
                $before = json_decode($stored,true) ?: [];
                foreach (['attachments','archivedAt','archivedBy','projectNo'] as $key) {
                    if (array_key_exists($key,$before)) $record[$key]=$before[$key]; else unset($record[$key]);
                }
                $json = json_encode($record, JSON_UNESCAPED_UNICODE);
                $columns = $this->projectColumns($record);
                $storedUpdatedAt = (string) ((json_decode($stored, true)['updatedAt'] ?? ''));
                if ($storedUpdatedAt !== '' && ($clientUpdatedAt === '' || ! hash_equals($storedUpdatedAt, $clientUpdatedAt))) {
                    return response()->json(['ok' => false, 'message' => 'This record was updated by another user. Refresh before saving.'], 409);
                }
                $updated = DB::table('projects')->where('id', $id)->whereRaw("COALESCE((data->>'updatedAt'), '') = ?", [$storedUpdatedAt])->update(['data' => $json] + $columns + ['updated_by' => $user['id'], 'updated_at' => now()]);
                if (! $updated) {
                    return response()->json(['ok' => false, 'message' => 'This record changed while you were saving. Refresh and try again.'], 409);
                }
            } else {
                DB::transaction(function () use (&$id, &$record, &$json, $user) {
                    $record['projectNo'] = \App\Support\TrackerProjectNumber::allocate();
                    $json = json_encode($record, JSON_UNESCAPED_UNICODE);
                    $id = DB::table('projects')->insertGetId(['data' => $json] + $this->projectColumns($record) + ['updated_by' => $user['id'], 'updated_at' => now()]);
                });
            }
        } else {
            $values = ['data' => $json, 'project_id' => (int) ($record['projectId'] ?? 0) ?: null, 'event_at' => ($stamp = strtotime((string)($record['editedAt'] ?? $record['timestamp'] ?? ''))) !== false ? gmdate('Y-m-d H:i:s', $stamp) : null, 'updated_by' => $user['id'], 'updated_at' => now()];
            if ($id) {
                $stored = DB::table('history')->where('id', $id)->value('data');
                if ($stored === null) {
                    return response()->json(['ok' => false, 'message' => 'This record no longer exists. Refresh and try again.'], 409);
                }
                $storedUpdatedAt = (string) ((json_decode($stored, true)['updatedAt'] ?? ''));
                if ($storedUpdatedAt !== '' && ($clientUpdatedAt === '' || ! hash_equals($storedUpdatedAt, $clientUpdatedAt))) {
                    return response()->json(['ok' => false, 'message' => 'This record was updated by another user. Refresh before saving.'], 409);
                }
                $updated = DB::table('history')->where('id', $id)->whereRaw("COALESCE((data->>'updatedAt'), '') = ?",[$storedUpdatedAt])->update($values);
                if (!$updated) return response()->json(['ok'=>false,'message'=>'History changed while saving. Refresh and try again.'],409);
            } else {
                $id = DB::table('history')->insertGetId($values);
            }
        }
        if ($project && $request->input('action') !== 'saveTimelineRecord') {
            TrackerHistory::append($id, $before ?? [], $record, $user, isset($before) ? 'Edited' : 'Created');
        }
        $this->audit($user, $project ? 'project.saved' : 'history.saved', $project ? 'project' : 'history', $id, $project ? 'Saved project: '.($record['title'] ?? '') : 'Saved project history entry.');
        $record['id'] = $id;

        return response()->json(['ok' => true, 'record' => $record]);
    }

    private function csvText(mixed $value): string
    {
        $text = is_scalar($value) ? (string)$value : '';
        return preg_match('/^[\s\x00-\x1f]*[=+@-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
    }

    /** Commit an alert change, its history, and audit entry as one database write. */
    private function saveTimelineRecord(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['canResolveAlerts'])) {
            return response()->json(['ok'=>false,'message'=>'Alert permission is required.'],403);
        }
        $request->validate(['record'=>['required','array'],'record.id'=>['required','integer','min:1'],'history'=>['required','array'],'history.action'=>['required','string','max:80'],'history.details'=>['sometimes','nullable','string','max:2000']]);
        return DB::transaction(function () use ($request,$user) {
            $record=$request->input('record');
            $row=DB::table('projects')->where('id',$record['id'])->lockForUpdate()->first();
            if (!$row) return response()->json(['ok'=>false,'message'=>'Project not found.'],404);
            $before=json_decode($row->data,true) ?: [];
            $allowed=array_flip(['id','updatedAt','updatedBy','timelineAlertsCompleted','timelineCompletedMarked','timelineMarkedDoneDate','timelineUnmarkedType','timelineUnmarkedAt']);
            if (array_diff_key($before,$allowed) != array_diff_key($record,$allowed)) {
                return response()->json(['ok'=>false,'message'=>'Alert permission only allows restoring historical alerts.'],403);
            }
            $response=$this->saveRecord($request,'saveProject');
            if ($response->getStatusCode() !== 200) return $response;
            $history=['projectId'=>(int)$record['id'],'timestamp'=>now('UTC')->format('Y-m-d\TH:i:s.u\Z'),'action'=>$request->input('history.action'),'details'=>$request->input('history.details',''),'updatedBy'=>$user['username'],'actor'=>TrackerHistory::actor($user)];
            DB::table('history')->insert(['data'=>json_encode($history,JSON_UNESCAPED_UNICODE),'project_id'=>$record['id'],'event_at'=>now(),'updated_by'=>$user['id'],'updated_at'=>now()]);
            return $response;
        });
    }

    private function deleteRecord(Request $request, string $action): JsonResponse
    {
        return app(TrackerDeletionController::class)->requestFor($request, $action === 'deleteProject' ? 'project' : 'history');
    }

    private function setArchived(Request $request): JsonResponse
    {
        $request->validate(['archived'=>['required','boolean']]);
        $user = $this->user($request);
        if (empty($user['canArchive'])) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to archive projects.'], 403);
        }
        $id = (int) $request->input('id');
        $archived = $request->boolean('archived');
        $record = DB::transaction(function () use ($id, $archived, $user) {
            $row = DB::table('projects')->where('id', $id)->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $before = json_decode($row->data, true) ?: [];
            if (($row->archived_at !== null) === $archived) {
                return $before;
            }
            $record = $before;
            if ($archived) {
                $record['archivedAt'] = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
                $record['archivedBy'] = $user['username'];
            } else {
                unset($record['archivedAt'], $record['archivedBy']);
            }
            $record['updatedBy'] = $user['username'];
            $record['updatedAt'] = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
            DB::table('projects')->where('id', $id)->update(['data' => json_encode($record, JSON_UNESCAPED_UNICODE), 'archived_at' => $archived ? now() : null, 'updated_by' => $user['id'], 'updated_at' => now()]);
            $history = ['projectId' => $id, 'editedAt' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'changes' => json_encode($before, JSON_UNESCAPED_UNICODE), 'action' => $archived ? 'Archived' : 'Restored', 'updatedBy' => $user['username'], 'actor' => TrackerHistory::actor($user)];
            DB::table('history')->insert(['data' => json_encode($history, JSON_UNESCAPED_UNICODE), 'project_id' => $id, 'event_at' => now(), 'updated_by' => $user['id'], 'updated_at' => now()]);
            $this->audit($user, $archived ? 'project.archived' : 'project.restored', 'project', $id, $archived ? 'Archived project.' : 'Restored project.');

            return $record;
        });
        if ($record === null) {
            return response()->json(['ok' => false, 'message' => 'Project not found.'], 404);
        }
        $record['id'] = $id;

        return response()->json(['ok' => true, 'record' => $record]);
    }

    private function saveTimelineSettings(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user['role'] !== 'admin') {
            return response()->json(['ok' => false, 'message' => 'Only administrators can change timeline settings.'], 403);
        }
        $settings = $request->input('settings');
        if (! is_array($settings)) {
            return response()->json(['ok' => false, 'message' => 'Timeline settings must be an object.'], 422);
        }
        $valid = [];
        $current = $this->timelineSettings();
        foreach (['noa', 'ntp', 'material', 'completion', 'finalInspection', 'bidding', 'contractEnd'] as $key) {
            $value = filter_var((in_array($key, ['finalInspection', 'bidding', 'contractEnd'], true) && ! array_key_exists($key, $settings ?? [])) ? $current[$key] : ($settings[$key] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
            if ($value === false) {
                return response()->json(['ok' => false, 'message' => 'Timeline days must be whole numbers from 1 to 3,650.'], 422);
            } $valid[$key] = $value;
        }
        DB::table('application_settings')->updateOrInsert(['setting_key' => 'timeline_days'], ['setting_value' => json_encode($valid), 'updated_by' => $user['id'], 'updated_at' => now()]);

        return response()->json(['ok' => true, 'settings' => $valid]);
    }

    private function clearStore(Request $request): JsonResponse
    {
        $request->validate(['store' => 'required|in:projects,history']);
        return app(TrackerDeletionController::class)->requestFor($request, $request->input('store') === 'projects' ? 'project' : 'history', true);
    }

    public static function removeAttachmentDirectory(int $projectId, array $project): void
    {
        if (\App\Support\TrackerMediaStore::cloud()) {
            foreach ($project['attachments'] ?? [] as $attachment) {
                $name=basename((string)($attachment['storedName'] ?? ''));
                if ($name) { try { \App\Support\TrackerMediaStore::delete('attachments',$projectId.'/'.$name); } catch (\Throwable $error) { report($error); } }
            }
            return;
        }
        $directory = rtrim((string) config('tracker.attachments'), '\\/').DIRECTORY_SEPARATOR.$projectId;
        foreach ($project['attachments'] ?? [] as $attachment) {
            $name = basename((string) ($attachment['storedName'] ?? ''));
            if ($name && is_file($directory.DIRECTORY_SEPARATOR.$name)) {
                @unlink($directory.DIRECTORY_SEPARATOR.$name);
            }
        }
        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }

    /** Stage an upload; no project/history/numbering writes until completeImport. */
    private function bulkSave(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['canImport'])) return response()->json(['ok'=>false,'message'=>'Import permission is required.'],403);
        $request->validate(['batchId'=>'required|string|regex:/^[A-Za-z0-9_-]{12,64}$/D','records'=>'required|array|min:1|max:5000', 'chunkIndex'=>'sometimes|integer|min:0', 'expectedRows'=>'sometimes|integer|min:1']);
        $batch = (string)$request->input('batchId');
        $records = array_map(fn ($record) => is_array($record) ? \App\Support\TrackerProjectInput::withoutBlankAdditionalImportFields($record) : $record, $request->input('records'));
        foreach ($records as $record) {
            if (!is_array($record) || trim((string)($record['title'] ?? '')) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages(['records'=>'Every imported row requires a project title.']);
            }
            \App\Support\TrackerProjectInput::validate($record);
            $previous = !empty($record['id']) ? (json_decode((string)DB::table('projects')->where('id',$record['id'])->value('data'),true) ?: []) : [];
            \App\Support\TrackerProjectInput::validateDates(array_replace($previous, $record), $previous);
        }
        return DB::transaction(function () use ($request,$user,$batch,$records) {
            DB::table('project_number_sequences')->where('sequence_name','projects')->lockForUpdate()->first();
            $job = DB::table('import_jobs')->where('batch_id',$batch)->lockForUpdate()->first();
            if ($job && ((int)$job->actor_id !== (int)$user['id'] || $job->status !== 'running' || $job->rolled_back_at)) {
                return response()->json(['ok'=>false,'message'=>'This import batch is unavailable. Start a new import.'],409);
            }
            if (!$job) DB::table('import_jobs')->insert(['batch_id'=>$batch,'actor_id'=>$user['id'],'status'=>'running']);
            $index = $request->input('chunkIndex');
            if ($index === null) $index = (int)(DB::table('import_staging_chunks')->where('batch_id',$batch)->max('chunk_index') ?? -1) + 1;
            $values = ['records'=>json_encode($records,JSON_UNESCAPED_UNICODE), 'archive'=>$request->boolean('archive'), 'expected_rows'=>$request->input('expectedRows')];
            $old = DB::table('import_staging_chunks')->where('batch_id',$batch)->where('chunk_index',$index)->first();
            if ($old && (json_decode($old->records,true) != $records || (bool)$old->archive !== $values['archive'] || $old->expected_rows != $values['expected_rows'])) {
                return response()->json(['ok'=>false,'message'=>'This upload chunk changed. Start a new import.'],409);
            }
            if (!$old) DB::table('import_staging_chunks')->insert(['batch_id'=>$batch,'chunk_index'=>$index]+$values);
            return response()->json(['ok'=>true,'count'=>count($records),'batchId'=>$batch,'staged'=>true]);
        });
    }

    private function applyImport(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['canImport'])) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to import projects.'], 403);
        }
        $records = $request->input('records');
        if (! is_array($records) || ! $records) {
            return response()->json(['ok' => false, 'message' => 'Supply between 1 and 5,000 project rows.'], 422);
        }
        $archiveImport = $request->boolean('archive');
        $batch = trim((string) $request->input('batchId'));
        if (! preg_match('/^[A-Za-z0-9_-]{12,64}$/', $batch)) {
            return response()->json(['ok' => false, 'message' => 'Invalid import batch id.'], 422);
        }
        try {
            $count = DB::transaction(function () use ($records, $batch, $user, $archiveImport) {
                \App\Support\TrackerProjectNumber::synchronize();
                $job = DB::table('import_jobs')->where('batch_id', $batch)->lockForUpdate()->first();
                if ($job && ((int) $job->actor_id !== (int) $user['id'] || $job->status !== 'running' || $job->rolled_back_at)) {
                    throw new \DomainException('This import batch is unavailable. Start a new import.');
                }
                if (! $job) {
                    DB::table('import_jobs')->insert(['batch_id' => $batch, 'actor_id' => $user['id'], 'status' => 'running']);
                }
                $count = 0;
                $reservedNumbers = [];
                foreach ($records as $incoming) {
                    if (empty($incoming['id']) && empty($incoming['autoProjectNumber'])) {
                        $reservedNumbers[trim((string) ($incoming['projectNo'] ?? ''))] = true;
                    }
                }
                foreach ($records as $record) {
                    if (is_array($record)) \App\Support\TrackerProjectInput::validate($record);
                    if (! is_array($record) || trim((string) ($record['title'] ?? '')) === '') {
                        throw new \DomainException('Every imported row requires a project title.');
                    }
                    $record = TrackerProjectRevision::normalizeFields($record);
                    foreach (self::PROJECT_DATE_FIELDS as $field) {
                        if (trim((string) ($record[$field] ?? '')) !== '' && ! self::validProjectDate($record[$field])) {
                            throw new \DomainException("Invalid {$field} date in an imported row.");
                        }
                    }
                    $id = is_numeric($record['id'] ?? null) ? (int) $record['id'] : 0;
                    $automaticNumber = ($record['autoProjectNumber'] ?? false) === true;
                    unset($record['autoProjectNumber'], $record['id'], $record['attachments'], $record['archivedAt'], $record['archivedBy'], $record['timelineAlertsCompleted'], $record['timelineCompletedMarked'], $record['timelineMarkedDoneDate'], $record['timelineUnmarkedType'], $record['timelineUnmarkedAt']);
                    $existing = $id ? DB::table('projects')->where('id', $id)->lockForUpdate()->first() : null;
                    if ($id && ! $existing) {
                        throw new \DomainException("Project ID $id no longer exists.");
                    }
                    $previous = $existing?->data;
                    $original = [];
                    if ($existing) {
                        // Spreadsheet rows contain only exported fields. Retain attachments and
                        // other project metadata, as well as the existing archive state.
                        $original = json_decode($previous, true) ?: [];
                        $version = (string)($original['updatedAt'] ?? '');
                        if ($version !== '' && !hash_equals($version,(string)($record['updatedAt'] ?? ''))) {
                            throw new \DomainException('This project changed since import review. Refresh and review the spreadsheet again.');
                        }
                        $record = array_replace($original, $record);
                        $record['projectNo'] = $original['projectNo'] ?? \App\Support\TrackerProjectNumber::allocate($reservedNumbers);
                        if ($existing->archived_at === null) {
                            unset($record['archivedAt'], $record['archivedBy']);
                        } else {
                            $record['archivedAt'] = $original['archivedAt'] ?? date('c', strtotime($existing->archived_at));
                            $record['archivedBy'] = $original['archivedBy'] ?? null;
                        }
                    } else {
                        if ($automaticNumber || trim((string) ($record['projectNo'] ?? '')) === '') {
                            $record['projectNo'] = \App\Support\TrackerProjectNumber::allocate($reservedNumbers);
                        }
                        if ($archiveImport) {
                            $record['archivedAt'] = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
                            $record['archivedBy'] = $user['username'];
                        }
                    }
                    $projectNo = trim((string) ($record['projectNo'] ?? ''));
                    if ($projectNo !== '' && DB::table('projects')->where('project_no', $projectNo)->when($id > 0, fn ($query) => $query->where('id', '<>', $id))->exists()) {
                        throw new \DomainException("Project number {$projectNo} already exists. Choose Add as new with a new number or overwrite the matching project.");
                    }
                    \App\Support\TrackerProjectInput::validateDates($record, isset($original) ? $original : []);
                    $record = \App\Support\TrackerProjectInput::normalize($record);
                    $record['updatedBy'] = $user['username'];
                    $record['updatedAt'] = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
                    $json = json_encode($record, JSON_UNESCAPED_UNICODE);
                    $values = ['data' => $json] + $this->projectColumns($record) + ['updated_by' => $user['id'], 'updated_at' => now()];
                    $values['archived_at'] = $existing?->archived_at ?? ($archiveImport && ! $id ? now() : null);
                    if ($id) {
                        DB::table('projects')->where('id', $id)->update($values);
                        DB::table('import_job_changes')->insert(['batch_id' => $batch, 'project_id' => $id, 'change_type' => 'updated', 'previous_data' => $previous, 'applied_data' => $json]);
                    } else {
                        $id = DB::table('projects')->insertGetId($values);
                        DB::table('import_job_changes')->insert(['batch_id' => $batch, 'project_id' => $id, 'change_type' => 'created', 'applied_data' => $json]);
                    }
                    TrackerHistory::append($id, $original, $record, $user, $existing ? 'Imported update' : 'Imported');
                    $count++;
                }
                DB::table('import_jobs')->where('batch_id', $batch)->increment('processed_count', $count);
                \App\Support\TrackerProjectNumber::synchronize();
                $this->audit($user, 'project.bulk_imported', 'project', null, "Imported $count project rows.");

                return $count;
            });
        } catch (\DomainException $error) {
            return response()->json(['ok' => false, 'message' => $error->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'count' => $count, 'batchId' => $batch]);
    }

    private function completeImport(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['canImport'])) return response()->json(['ok'=>false,'message'=>'Import permission is required.'],403);
        $batch = trim((string) $request->input('batchId'));
        if (! preg_match('/^[A-Za-z0-9_-]{12,64}$/', $batch)) {
            return response()->json(['ok' => false, 'message' => 'Invalid import batch id.'], 422);
        }
        try {
            return DB::transaction(function () use ($batch,$user,$request) {
                DB::table('project_number_sequences')->where('sequence_name','projects')->lockForUpdate()->first();
                $job = DB::table('import_jobs')->where('batch_id',$batch)->where('actor_id',$user['id'])->lockForUpdate()->first();
                if ($job && $job->status === 'completed' && !$job->rolled_back_at) return response()->json(['ok'=>true,'count'=>(int)$job->processed_count]);
                if (!$job || $job->status !== 'running' || $job->rolled_back_at) throw new \DomainException('This import batch cannot be completed.');
                $chunks = DB::table('import_staging_chunks')->where('batch_id',$batch)->orderBy('chunk_index')->get();
                if ($chunks->isEmpty()) throw new \DomainException('No staged rows were uploaded. Start a new import.');
                $records = []; $archive = (bool)$chunks[0]->archive; $expected = $chunks[0]->expected_rows;
                foreach ($chunks as $index=>$chunk) {
                    if ((int)$chunk->chunk_index !== $index || (bool)$chunk->archive !== $archive || $chunk->expected_rows != $expected) throw new \DomainException('The upload is incomplete or inconsistent. Start a new import.');
                    array_push($records, ...json_decode($chunk->records,true));
                }
                if ($expected !== null && count($records) !== (int)$expected) throw new \DomainException('The upload is incomplete. No projects were saved.');
                $ids = array_filter(array_column($records,'id'));
                if (count($ids) !== count(array_unique($ids))) throw new \DomainException('A project appears more than once in the import. Review duplicate rows.');
                $commit = clone $request;
                $commit->merge(['records'=>$records,'archive'=>$archive]);
                $response = $this->applyImport($commit);
                if ($response->getStatusCode() !== 200) throw new \DomainException($response->getData()->message ?? 'Import failed.');
                DB::table('import_jobs')->where('batch_id',$batch)->update(['status'=>'completed','completed_at'=>now()]);
                DB::table('import_staging_chunks')->where('batch_id',$batch)->delete();
                return $response;
            });
        } catch (\DomainException $error) {
            return response()->json(['ok'=>false,'message'=>$error->getMessage()],422);
        }
    }

    private function rollbackImport(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['canImport'])) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to roll back imports.'], 403);
        }
        $batch = trim((string) $request->input('batchId'));
        if (! preg_match('/^[A-Za-z0-9_-]{12,64}$/', $batch)) {
            return response()->json(['ok' => false, 'message' => 'Invalid import batch id.'], 422);
        }
        try {
            $count = DB::transaction(function () use ($batch, $user) {
                DB::table('project_number_sequences')->where('sequence_name','projects')->lockForUpdate()->first();
                $job = DB::table('import_jobs')->where('batch_id', $batch)->where('actor_id', $user['id'])->lockForUpdate()->first();
                if (! $job) {
                    throw new \DomainException('Import job not found.');
                } if ($job->rolled_back_at) {
                    throw new \DomainException('This import was already rolled back.');
                }
                DB::table('import_staging_chunks')->where('batch_id',$batch)->delete();
                // Import rollback must not provide another route to delete saved projects.
                if (DB::table('import_job_changes')->where('batch_id', $batch)->where('change_type', 'created')->exists()) {
                    throw new \DomainException('This rollback would delete saved projects. Submit deletion requests for the imported projects so an administrator can review and confirm them.');
                }
                $count = 0;
                foreach (DB::table('import_job_changes')->where('batch_id', $batch)->orderByDesc('id')->get() as $change) {
                    $current = DB::table('projects')->where('id',$change->project_id)->lockForUpdate()->first();
                    if (!$change->applied_data || !$current || json_decode($current->data,true) != json_decode($change->applied_data,true)) {
                        throw new \DomainException('Rollback stopped: a project changed after import, was deleted, or this legacy import has no safe rollback snapshot.');
                    }

                    if ($change->change_type === 'created') {
                        DB::table('projects')->where('id', $change->project_id)->delete();
                    } else {
                        $record = json_decode((string) $change->previous_data, true) ?: [];
                        $record['updatedAt'] = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
                        $record['updatedBy'] = $user['username'];
                        TrackerHistory::append((int)$change->project_id, json_decode($current->data,true), $record, $user, 'Import rollback');
                        DB::table('projects')->where('id', $change->project_id)->update(['data' => json_encode($record,JSON_UNESCAPED_UNICODE)] + $this->projectColumns($record) + ['archived_at' => ! empty($record['archivedAt']) ? date('Y-m-d H:i:s', strtotime($record['archivedAt'])) : null, 'updated_by' => $user['id'], 'updated_at' => now()]);
                    }
                    $count++;
                }
                DB::table('import_jobs')->where('batch_id', $batch)->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);
                \App\Support\TrackerProjectNumber::synchronize();
                $this->audit($user, 'project.import_rolled_back', 'import_job', null, "Rolled back import $batch.");

                return $count;
            });
        } catch (\DomainException $error) {
            return response()->json(['ok' => false, 'message' => $error->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'count' => $count]);
    }

    private function barangayFilterPattern(string $input): string
    {
        $input = strtolower(trim($input));
        $input = preg_replace('/^(?:brgy|barangay)\.?\s*#?\s*/', '', $input);
        $prefix = '(^|[^[:alnum:]])(brgy[.]?|barangay)[[:space:]]*#?[[:space:]]*';
        if (preg_match('/^0*(\d+)(?:\s*-\s*([a-z]))?$/', $input, $matches)) {
            $suffix = isset($matches[2]) ? '[[:space:]]*-[[:space:]]*'.$matches[2] : '';

            return $prefix.'0*'.(int) $matches[1].$suffix.'($|[^[:alnum:]_-])';
        }
        if ($input === '') {
            return $prefix;
        }

        return $prefix.'([0-9]+(-[a-z])?[[:space:]]+)?'.preg_quote($input, '/').'($|[^[:alnum:]_-])';
    }
    private function projectStatusDate(array $project): ?string
    {
        $status = strtolower(trim((string) ($project['status'] ?? 'pending')));
        $keys = match ($status) {
            'completed', 'released' => ['completion', 'finalInspection', 'biddingDate'],
            'final-inspection' => ['finalInspection'],
            'pre-inspection' => ['preInspection', 'ntpDate', 'biddingDate'],
            default => ['biddingDate'],
        };
        foreach ($keys as $key) {
            if (self::validProjectDate($project[$key] ?? null)) {
                return (string) $project[$key];
            }
        }

        return null;
    }

    private function projectDate(array $project, string $key): ?\DateTimeImmutable
    {
        $value = (string) ($project[$key] ?? '');
        if (! self::validProjectDate($value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    private function alertResolved(array $project, string $type, \DateTimeImmutable $today): bool
    {
        foreach (TrackerMilestones::automaticResolutions($project, $today) as $resolved) {
            if (in_array($type, $resolved['aliases'], true)) return true;
        }
        $completed = is_array($project['timelineAlertsCompleted'] ?? null) ? $project['timelineAlertsCompleted'] : [];
        if (in_array($type, $completed, true)) {
            return true;
        }
        if (in_array($type, ['award-close', 'award-overdue'], true)) {
            return false;
        }

        return ! empty($project['timelineCompletedMarked']);
    }

    private function projectAlerts(array $project, array $settings, \DateTimeImmutable $today): array
    {
        foreach (['completion','swa'] as $field) {
            if (TrackerMilestones::date($project[$field] ?? null) && $project[$field] > $today->format('Y-m-d')) {
                $project['status'] = \App\Support\TrackerProjectInput::effectiveStatus($project,$today); break;
            }
        }
        $alerts = TrackerMilestones::alerts($project, $settings, $today);
        $push = function (string $type, string $label) use (&$alerts, $project, $today): void {
            if (! $this->alertResolved($project, $type, $today)) {
                $due = str_starts_with($type, 'award') && ($completion=$this->projectDate($project, 'completion')) ? TrackerMilestones::weekday($completion->modify('+1 year'))->format('Y-m-d') : null;
                $alerts[] = ['type' => $type, 'label' => $label, 'project' => $project, 'dueDate'=>$due];
            }
        };
        $completed = in_array(strtolower(trim((string) ($project['status'] ?? ''))), ['completed', 'released'], true);
        if (! $completed) {
            if (! TrackerMilestones::date($project['noaDueDate'] ?? null) && ($noa = $this->projectDate($project, 'noa'))) {
                $warning = TrackerMilestones::addWorkdays($noa, -$settings['noa']);
                $deadline = TrackerMilestones::addWorkdays($noa, $settings['noa']);
                if ($today >= $warning && $today < $noa) {
                    $push('noa-warning', 'NOA Warning');
                }
                if ($today >= $noa && $today <= $deadline) {
                    $push('noa-active', 'NOA Active');
                }
                if ($today > $deadline) {
                    $push('noa-overdue', 'NOA Overdue');
                }
            }
            if ($ntp = $this->projectDate($project, 'ntpDate')) {
                $material = TrackerMilestones::addWorkdays($ntp, $settings['material']);
                if (! TrackerMilestones::date($project['biddingDate'] ?? null) && ! $this->alertResolved($project, 'material-test', $today) && ! $this->alertResolved($project, 'material-overdue', $today)) {
                    if ($today >= $ntp && $today <= $material) {
                        $push('material-test', 'Material Test');
                    } elseif ($today > $material) {
                        $push('material-overdue', 'Material Test Overdue');
                    }
                }
                $award = TrackerMilestones::addWorkdays($ntp, $settings['ntp']);
                if (! TrackerMilestones::date($project['biddingDate'] ?? null) && ! $this->alertResolved($project, 'ntp-award', $today) && ! $this->alertResolved($project, 'ntp-overdue', $today)) {
                    if ($today >= $ntp && $today <= $award) {
                        $push('ntp-award', 'NTP Award');
                    } elseif ($today > $award) {
                        $push('ntp-overdue', 'NTP Overdue');
                    }
                }
            }
        }
        $swa = $this->projectDate($project, 'swa');
        if (strtolower(trim((string)($project['status'] ?? ''))) !== 'released' && (!$swa || $swa->format('Y-m-d') > $today->format('Y-m-d')) && ($completion = $this->projectDate($project, 'completion'))) {
            $due = TrackerMilestones::weekday($completion->modify('+1 year'));
            if ($today >= $due) {
                $push('award-overdue', 'SWA Due — Notify Contractor');
            } elseif ($today >= TrackerMilestones::addWorkdays($due, -$settings['completion'])) {
                $push('award-close', 'SWA Reminder — Notify Contractor');
            }
        }

        return $alerts;
    }

    private function timelineSettings(): array
    {
        $saved = json_decode((string) DB::table('application_settings')->where('setting_key', 'timeline_days')->value('setting_value'), true) ?: [];
        return array_replace(['noa' => 10, 'ntp' => 30, 'material' => 15, 'completion' => 15, 'finalInspection' => 7, 'contractEnd' => 7, 'bidding' => $saved['material'] ?? 15], $saved);
    }

    public static function projectColumns(array $r): array
    {
        $text = fn ($k) => ($value = trim((string) ($r[$k] ?? ''))) === '' ? null : $value;
        $date = fn ($k) => self::validProjectDate($r[$k] ?? null) ? $r[$k] : null;
        $money = function ($k) use ($r) {
            $amount = preg_replace('/[₱$,\s]/u', '', (string) ($r[$k] ?? ''));
            return is_numeric($amount) ? $amount : null;
        };

        return ['project_no' => $text('projectNo'), 'title' => $text('title'), 'project_status' => $text('status'), 'bidding_date' => $date('biddingDate'), 'completion_date' => $date('completion'), 'noa_date' => $date('noa'), 'ntp_date' => $date('ntpDate'), 'winning_bidder' => $text('winningBidder'), 'source_of_fund' => $text('sourceOfFund'), 'pr_number' => $text('prNumber'), 'approved_budget' => $money('approvedBudget'), 'bid_amount' => $money('bidAmount')];
    }

    private function user(Request $request): array
    {
        return (array) $request->session()->get('tracker_user');
    }

    private function audit(array $user, string $action, string $type, ?int $id, string $summary): void
    {
        DB::table('audit_log')->insert(['actor_id' => $user['id'], 'actor_username' => $user['username'], 'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'summary' => $summary, 'created_at' => now()]);
    }
}
