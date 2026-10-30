<?php
namespace Tests\Feature;
use Tests\TestCase;
class SwaReminderTest extends TestCase {
 public function test_future_swa_does_not_suppress_current_reminders_but_legacy_undated_release_is_retained():void {
  $controller=new \App\Http\Controllers\TrackerDataController;
  $method=new \ReflectionMethod($controller,'projectAlerts');
  foreach([['status'=>'Released','swa'=>'2026-10-14'],['status'=>'Released'],['status'=>'Completed','swa'=>'2026-10-14']] as $extra){
   $alerts=$method->invoke($controller,['completion'=>'2025-10-08',...$extra],['noa'=>7,'ntp'=>7,'material'=>7,'completion'=>15],new \DateTimeImmutable('2026-10-07'));
   if (isset($extra['swa'])) $this->assertContains('award-close',array_column($alerts,'type'));
   else $this->assertNotContains('award-close',array_column($alerts,'type'));
   $this->assertNotContains('award-overdue',array_column($alerts,'type'));
  }
 }
 public function test_swa_reminder_uses_completion_even_while_pending():void {
  $controller=new \App\Http\Controllers\TrackerDataController;
  $method=new \ReflectionMethod($controller,'projectAlerts');
  $project=['status'=>'Pending','completion'=>'2025-10-08'];
  $settings=['noa'=>7,'ntp'=>7,'material'=>7,'finalInspection'=>7,'completion'=>15];
  $alerts=$method->invoke($controller,$project,$settings,new \DateTimeImmutable('2026-10-07'));
  $swa=array_values(array_filter($alerts,fn($a)=>$a['type']==='award-close'));
  $this->assertCount(1,$swa);$this->assertSame('2026-10-08',$swa[0]['dueDate']);
  $alerts=$method->invoke($controller,$project,$settings,new \DateTimeImmutable('2026-10-08'));
  $this->assertContains('award-overdue',array_column($alerts,'type'));
  $project['swa']='2026-10-07';
  $alerts=$method->invoke($controller,$project,$settings,new \DateTimeImmutable('2026-10-08'));
  $this->assertNotContains('award-overdue',array_column($alerts,'type'));
 }
}
