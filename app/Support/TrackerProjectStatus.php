<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
/** Status is a clock/calendar projection, not a user edit or a new draft version. */
final class TrackerProjectStatus
{
    public static function refresh(): int
    {
        $scope = DB::connection()->getDatabaseName();
        if (DB::connection()->getDriverName() === 'pgsql') $scope .= ':'.DB::selectOne('SELECT current_schema() AS name')->name;
        $key = 'tracker-status:'.hash('sha256',$scope);
        $revision = hash('sha256',json_encode([now('Asia/Manila')->format('Y-m-d'), TrackerHolidays::state()['revision'],
            DB::table('audit_log')->whereIn('entity_type',['project','projects','import_job'])->max('id'), DB::table('projects')->count()]));
        if (Cache::get($key) === $revision) return 0;
        $changed = 0;
        DB::table('projects')->select('id','data','project_status')->orderBy('id')->chunkById(500, function ($rows) use (&$changed) {
            foreach ($rows as $row) {
                $data = json_decode($row->data,true) ?: [];
                $today = now('Asia/Manila')->format('Y-m-d');
                $end = TrackerMilestones::dueDates($data)['contractDueDate'];
                $futureActual = false;
                foreach (['completion','swa'] as $field) if (TrackerMilestones::date($data[$field] ?? null) && $data[$field] > $today) $futureActual = true;
                // Keep legacy manual states without a deadline; refresh clock/calendar transitions.
                if (!$futureActual && !$end) continue;
                $status = TrackerProjectInput::automaticStatus($data);
                if (($data['status'] ?? null) === $status && $row->project_status === $status) continue;
                $data['status'] = $status;
                // An intervening save wins; never overwrite any changed project fields.
                $changed += DB::table('projects')->where('id',$row->id)->where('data',$row->data)->update(['data'=>json_encode($data,JSON_UNESCAPED_UNICODE),'project_status'=>$status]);
            }
        });
        Cache::put($key,$revision,86400);
        return $changed;
    }
}
