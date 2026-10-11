<?php

namespace App\Http\Controllers;

use App\Support\TrackerHolidays;
use App\Support\TrackerMilestones;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class TrackerHolidayController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $role = $request->session()->get('tracker_user.role');
        if ($role !== 'superadmin') return response()->json(['ok'=>false,'message'=>'Only Superadmin can view the holiday settings.'],403);
        if ($request->isMethod('get')) return response()->json(TrackerHolidays::status());
        if ($role !== 'superadmin') return response()->json(['ok'=>false,'message'=>'Only Superadmin can change or sync holidays.'],403);
        $input = $request->validate(['action'=>['required','in:settings,sync,override,restore'], 'revision'=>['required','integer','min:0'], 'enabled'=>['sometimes','required','boolean'], 'nonWorkingDays'=>['sometimes','array','max:6'], 'nonWorkingDays.*'=>['integer','between:0,6','distinct'], 'excludeHolidays'=>['sometimes','required','boolean'], 'date'=>['sometimes','required','date_format:Y-m-d'], 'name'=>['sometimes','required','string','max:160'], 'nonWorking'=>['sometimes','required','boolean']]);
        try {
            if ($input['action'] === 'sync') {
                if (TrackerHolidays::state()['revision'] !== (int)$input['revision']) throw new \DomainException('The calendar changed. Refresh it before syncing.');
                return response()->json(TrackerHolidays::sync(true));
            }
            if ($input['action'] === 'settings' && ! array_key_exists('enabled',$input)) return response()->json(['ok'=>false,'message'=>'Choose whether automatic sync is enabled.'],422);
            if (in_array($input['action'],['override','restore'],true) && ! TrackerMilestones::date($input['date'] ?? null)) return response()->json(['ok'=>false,'message'=>'Enter a valid holiday date.'],422);
            if ($input['action'] === 'override' && (trim($input['name'] ?? '') === '' || ! array_key_exists('nonWorking',$input))) return response()->json(['ok'=>false,'message'=>'Enter a holiday name and whether it is a working day.'],422);
            TrackerHolidays::update((int)$input['revision'], function ($state) use ($input, $request) {
                if ($input['action'] === 'settings') {
                    $state['enabled'] = (bool)$input['enabled'];
                    if (array_key_exists('nonWorkingDays',$input)) $state['nonWorkingDays'] = array_map('intval',$input['nonWorkingDays']);
                    if (array_key_exists('excludeHolidays',$input)) $state['excludeHolidays'] = (bool)$input['excludeHolidays'];
                }
                if ($input['action'] === 'restore') unset($state['overrides'][$input['date']]);
                if ($input['action'] === 'override') $state['overrides'][$input['date']] = ['name'=>trim($input['name']),'nonWorking'=>(bool)$input['nonWorking']];
                $state['updatedBy'] = (int)$request->session()->get('tracker_user.id');
                $state['updatedAt'] = now('Asia/Manila')->toIso8601String();
                return $state;
            });
            return response()->json(TrackerHolidays::status());
        } catch (\DomainException $error) { return response()->json(['ok'=>false,'message'=>$error->getMessage()],409); }
        catch (\RuntimeException $error) { return response()->json(['ok'=>false,'message'=>'Holiday update failed. The last saved calendar is still active.'],503); }
    }
}
