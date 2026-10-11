<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Support\TrackerProjectInput as Input;
class AutoCompletedTest extends TestCase {
 public function test_swa_date_sets_released_with_highest_priority():void {
  foreach(['On-going Bidding','Pre-inspection','Pending','Final-inspection','Completed'] as $status) $this->assertSame('Released',Input::normalize(['swa'=>'2026-10-07','completion'=>'2026-10-06','status'=>$status])['status']);
  $this->assertSame('Released',Input::normalize(['swa'=>'2026-10-07','status'=>'Pending'])['status']);
 }
 public function test_three_milestones_are_required_for_pending():void {
  $p=['biddingDate'=>'2026-10-01','preInspection'=>'2026-10-02','materialTestingDate'=>'2026-10-05','ntpDate'=>'2026-10-06','status'=>'Pre-inspection'];
  $today=new \DateTimeImmutable('2026-10-07');
  $this->assertSame('Pending',Input::automaticStatus($p,$today));
  foreach(['preInspection','materialTestingDate','ntpDate'] as $field){$missing=$p;unset($missing[$field]);$this->assertSame('Pre-inspection',Input::automaticStatus($missing,$today));}
  $this->assertSame('On-going Bidding',Input::automaticStatus([],$today));
 }
 public function test_contract_end_and_completed_take_priority():void {
  $p=['biddingDate'=>'2026-10-01','preInspection'=>'2026-10-02','materialTestingDate'=>'2026-10-05','ntpDate'=>'2026-10-06','contractEndDate'=>'2026-10-08'];
  $this->assertSame('Pending',Input::automaticStatus($p,new \DateTimeImmutable('2026-10-07')));
  $this->assertSame('Final-inspection',Input::automaticStatus($p,new \DateTimeImmutable('2026-10-08')));
  $this->assertSame('Final-inspection',Input::automaticStatus($p,new \DateTimeImmutable('2026-10-09')));
  $p['finalInspection']='2026-10-08';$this->assertSame('Pending',Input::automaticStatus($p,new \DateTimeImmutable('2026-10-09')));
  $p['completion']='2026-10-09';$this->assertSame('Completed',Input::normalize($p)['status']);
  $p['status']='Released';$this->assertSame('Released',Input::normalize($p)['status']);
 }
}
