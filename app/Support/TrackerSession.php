<?php
namespace App\Support;
use Illuminate\Http\Request;
final class TrackerSession
{
    public static function expired(Request $request): bool
    {
        $created=(int)$request->session()->get('tracker_created_at',0);
        $last=(int)$request->session()->get('tracker_last_activity',0);
        return ($created && $created < time()-28800) || ($last && $last < time()-1800);
    }
}
