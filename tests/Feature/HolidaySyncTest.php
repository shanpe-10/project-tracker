<?php

namespace Tests\Feature;

use App\Support\TrackerHolidays;
use App\Support\TrackerMilestones;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidaySyncTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = sys_get_temp_dir().'/holiday-test-'.bin2hex(random_bytes(8)).'.json';
        config(['tracker_holidays.state_path'=>$this->statePath]);
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('username'); $table->string('full_name'); $table->string('role'); $table->boolean('active');
        });
        Route::middleware('web','tracker.auth')->match(['get','post'],'/api/holidays.php',[\App\Http\Controllers\TrackerHolidayController::class,'handle']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ([$this->statePath,$this->statePath.'.lock',$this->statePath.'.sync-lock'] as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }

    private function roleSession(string $role): array
    {
        $id=DB::table('users')->insertGetId(['username'=>$role,'full_name'=>$role,'role'=>$role,'active'=>true]);
        return ['tracker_user'=>['id'=>$id,'role'=>$role], 'tracker_csrf'=>'holiday-token','tracker_created_at'=>time(),'tracker_last_activity'=>time()];
    }

    public function test_roles_and_csrf_are_enforced_on_the_server(): void
    {
        $this->getJson('/api/holidays.php')->assertUnauthorized();
        $staff=$this->roleSession('staff');
        $this->withSession($staff)->getJson('/api/holidays.php')->assertForbidden();
        $admin=$this->roleSession('admin');
        $this->withSession($admin)->getJson('/api/holidays.php')->assertForbidden();
        $this->withSession($admin)->withHeader('X-CSRF-Token','holiday-token')->postJson('/api/holidays.php',['action'=>'settings','revision'=>0,'enabled'=>false])->assertForbidden();
        $super=$this->roleSession('superadmin');
        $this->withSession($super)->getJson('/api/holidays.php')->assertOk();
        $this->withSession($super)->withHeader('X-CSRF-Token','wrong')->postJson('/api/holidays.php',['action'=>'settings','revision'=>0,'enabled'=>false])->assertStatus(419);
        $this->withSession($super)->withHeader('X-CSRF-Token','holiday-token')->postJson('/api/holidays.php',['action'=>'settings','revision'=>0,'enabled'=>false])->assertOk()->assertJsonPath('state.enabled',false);
        $this->withSession($super)->withHeader('X-CSRF-Token','holiday-token')->postJson('/api/holidays.php',['action'=>'settings','revision'=>0,'enabled'=>true])->assertStatus(409);
        $this->withSession($super)->getJson('/api/data.php')->assertForbidden();
    }

    public function test_manual_overrides_survive_sync_and_restore_to_source(): void
    {
        TrackerHolidays::update(0,function ($state) { $state['overrides']['2026-12-25']=['name'=>'Manual correction','nonWorking'=>false]; return $state; });
        $this->assertTrue(TrackerMilestones::isWorkday(new \DateTimeImmutable('2026-12-25')));
        Http::fake(['*'=>Http::response('<?xml version="1.0"?><rss><channel/></rss>',200)]);
        TrackerHolidays::sync(true);
        $this->assertTrue(TrackerMilestones::isWorkday(new \DateTimeImmutable('2026-12-25')));
        TrackerHolidays::update(TrackerHolidays::state()['revision'],function ($state) { unset($state['overrides']['2026-12-25']); return $state; });
        $this->assertFalse(TrackerMilestones::isWorkday(new \DateTimeImmutable('2026-12-25')));
    }

    public function test_source_failure_preserves_calendar_and_records_failure(): void
    {
        $before=TrackerHolidays::calendar()['years'];
        Http::fake(['*'=>Http::response('unavailable',503)]);
        try { TrackerHolidays::sync(true); $this->fail('Expected sync failure'); } catch (\RuntimeException $error) { $this->assertStringContainsString('last saved',$error->getMessage()); }
        $this->assertSame($before,TrackerHolidays::calendar()['years']);
        $this->assertNotNull(TrackerHolidays::state()['error']);
        $this->assertNull(TrackerHolidays::state()['lastSuccess']);
    }

    public function test_sync_applies_only_explicit_national_dates_and_skips_daily_repeats(): void
    {
        $url='https://pco.gov.ph/news_releases/palace-declares-eidl-fitr/';
        $feed='<rss><channel><item><title>Palace declares Eid’l Fitr holiday</title><link>'.$url.'</link><pubDate>Thu, 01 Apr 2027 00:00:00 GMT</pubDate></item></channel></rss>';
        $html='<div class="entry-content"><p>Proclamation No. 9999 declares April 5, 2027 a regular holiday throughout the country in observance of Eid’l Fitr.</p></div>';
        Http::fake([$url=>Http::response($html,200),'*'=>Http::response($feed,200)]);
        $result=TrackerHolidays::sync(true);
        $this->assertSame('Eid’l Fitr',$result['calendar']['years']['2027']['2027-04-05']);
        $this->assertSame(['Eid’l Adha'],$result['calendar']['pending']['2027']);
        $this->assertSame('2027-04-06',TrackerMilestones::addWorkdays(new \DateTimeImmutable('2027-04-02'),1)->format('Y-m-d'));
        $count=count(Http::recorded()); TrackerHolidays::sync(); $this->assertCount($count,Http::recorded());
        $local='<div class="entry-content"><p>Proclamation declares April 5, 2027 a special non-working holiday in Laoag City.</p></div>';
        $this->assertSame([],TrackerHolidays::parseArticle($local,'Palace declares local holiday',2027));
        $estimated='<div class="entry-content"><p>The commission recommended April 5, 2027 as a regular holiday nationwide.</p></div>';
        $this->assertSame([],TrackerHolidays::parseArticle($estimated,'Eid holiday',2027));
    }

    public function test_malformed_source_records_failure_and_disabled_sync_makes_no_request(): void
    {
        TrackerHolidays::update(0,function ($state) { $state['enabled']=false; return $state; });
        TrackerHolidays::sync(); Http::assertNothingSent();
        Http::fake(['*'=>Http::response('not RSS',200)]);
        $this->expectException(\RuntimeException::class); TrackerHolidays::sync(true);
    }

    public function test_superadmin_can_select_non_working_weekdays_and_holiday_exclusions(): void
    {
        $super=$this->roleSession('superadmin');
        $this->withSession($super)->withHeader('X-CSRF-Token','holiday-token')->postJson('/api/holidays.php',['action'=>'settings','revision'=>0,'enabled'=>true,'nonWorkingDays'=>[0,1],'excludeHolidays'=>false])->assertOk()->assertJsonPath('calendar.nonWorkingDays',[0,1])->assertJsonPath('calendar.excludeHolidays',false);
        $this->assertTrue(TrackerMilestones::isWorkday(new \DateTimeImmutable('2026-10-03')));
        $this->assertFalse(TrackerMilestones::isWorkday(new \DateTimeImmutable('2026-10-05')));
        $this->assertTrue(TrackerMilestones::isWorkday(new \DateTimeImmutable('2026-12-25')));
        $this->assertSame('2026-10-03',TrackerMilestones::addWorkdays(new \DateTimeImmutable('2026-10-02'),1)->format('Y-m-d'));
        $this->withSession($super)->withHeader('X-CSRF-Token','holiday-token')->postJson('/api/holidays.php',['action'=>'settings','revision'=>1,'enabled'=>true,'nonWorkingDays'=>[0,1,2,3,4,5,6],'excludeHolidays'=>true])->assertUnprocessable();
        $this->assertSame([0,1],TrackerHolidays::calendar()['nonWorkingDays']);
    }

    public function test_project_dates_reject_non_working_days_and_added_holidays_but_preserve_existing_dates(): void
    {
        foreach (['2026-10-03','2026-12-25'] as $date) {
            try { \App\Support\TrackerProjectInput::validateDates(['biddingDate'=>$date]); $this->fail('Expected blocked date '.$date); }
            catch (\Illuminate\Validation\ValidationException $error) { $this->assertArrayHasKey('biddingDate',$error->errors()); }
        }
        \App\Support\TrackerProjectInput::validateDates(['biddingDate'=>'2026-10-03'],['biddingDate'=>'2026-10-03']);
        \App\Support\TrackerProjectInput::validateDates(['biddingDate'=>'2026-10-07']);
        TrackerHolidays::update(0,function ($state) { $state['overrides']['2026-10-07']=['name'=>'Added holiday','nonWorking'=>true]; return $state; });
        try { \App\Support\TrackerProjectInput::validateDates(['biddingDate'=>'2026-10-07']); $this->fail('Expected added holiday to be blocked'); }
        catch (\Illuminate\Validation\ValidationException $error) { $this->assertStringContainsString('excluded holiday',$error->errors()['biddingDate'][0]); }
    }
}
