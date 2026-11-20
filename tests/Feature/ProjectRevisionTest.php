<?php
namespace Tests\Feature;
use App\Http\Controllers\TrackerDataController;
use App\Support\{TrackerProjectInput,TrackerProjectRevision,TrackerProjectFields,TrackerMilestones,TrackerProjectStatus,FormattedProjectExport};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectRevisionTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        $this->useWeekdayCalendarFixture();
    }
    public function test_new_fields_preserve_text_identifiers_materials_and_legacy_data(): void {
        $record=['referenceNo'=>'0001-A','prNumber'=>'PR-0005','resolutionNumber'=>'00012','obreNumber'=>'00007','winningBidder'=>'Old contractor','contractRef'=>'2026-10-01','contractDuration'=>'5','endUserOffice'=>'Engineering','endUserName'=>'Alex','endUserPosition'=>'Engineer','address'=>'Laoag','physicalAccomplishment'=>'0','approvedBudgetWords'=>'One thousand'];
        for($i=1;$i<=10;$i++)$record['material'.$i]='Material '.$i;
        TrackerProjectInput::validate($record);TrackerProjectInput::validateDates($record);
        $normalized=TrackerProjectInput::normalize($record);
        foreach($record as $key=>$value)if($key!=='contractDuration')$this->assertSame($value,$normalized[$key]);
        $this->assertSame('2026-10-08',$normalized['contractEndDate']);
        $this->assertSame('NYS',TrackerProjectFields::display($normalized,'physicalAccomplishment'));
        $this->assertSame('',TrackerProjectFields::display([],'physicalAccomplishment'));
        $this->assertSame('12.5%',TrackerProjectFields::display(['physicalAccomplishment'=>'12.5'],'physicalAccomplishment'));
        $this->assertCount(7,TrackerProjectFields::sections());
    }
    public function test_na_clears_typed_fields_without_invalid_dates_and_reenables_normal_values(): void {
        $record=['ldAmount'=>' n/a ','ldNoOfDays'=>'N/A','ldNewCompletionDate'=>'N/A','ldActualDuration'=>'N/A','ldRemarks'=>'N/A'];
        TrackerProjectInput::validate($record);TrackerProjectInput::validateDates($record);
        $saved=TrackerProjectInput::normalize($record);
        $this->assertTrue($saved['ldNotApplicable']);
        $this->assertNull($saved['ldNewCompletionDate']);$this->assertNull($saved['ldActualDuration']);
        foreach(['ldAmount','ldNoOfDays','ldNewCompletionDate','ldActualDuration','ldRemarks'] as $key)$this->assertSame('N/A',TrackerProjectFields::display($saved,$key));
        $normal=TrackerProjectInput::normalize(['ldAmount'=>'subject to assessment','ldNoOfDays'=>'To follow','ldNewCompletionDate'=>'2026-10-08','ldActualDuration'=>'15.5']);
        TrackerProjectInput::validate($normal);TrackerProjectInput::validateDates($normal);
        $this->assertFalse($normal['ldNotApplicable']);$this->assertSame('2026-10-08',$normal['ldNewCompletionDate']);
    }
    public function test_manual_status_survives_milestones_edits_and_refresh_then_auto_recalculates(): void {
        $record=['title'=>'Manual status','statusMode'=>'manual','status'=>'Pending','manualStatus'=>'Pending','contractRef'=>'2026-10-01','contractDuration'=>'5','biddingDate'=>'2026-10-01'];
        $saved=TrackerProjectInput::normalize($record);$this->assertSame('Pending',$saved['status']);
        $id=DB::table('projects')->insertGetId(['data'=>json_encode($saved)]+TrackerDataController::projectColumns($saved));
        TrackerProjectStatus::refresh();$this->assertSame('Pending',DB::table('projects')->where('id',$id)->value('project_status'));
        $this->assertSame('Pending',TrackerProjectInput::normalize($saved+['owner'=>'New owner'])['status']);
        $saved['statusMode']='automatic';$auto=TrackerProjectInput::normalize($saved);
        $this->assertSame('Final-inspection',$auto['status']);$this->assertNull($auto['manualStatus']);
        $this->assertSame('Completed',TrackerProjectInput::normalize(['statusMode'=>'manual','status'=>'Completed'])['status']);
    }
    public function test_contract_end_followup_uses_shared_calendar_config_and_resolution_rules(): void {
        $project=['contractRef'=>'2026-10-01','contractDuration'=>5,'statusMode'=>'automatic'];
        $dates=TrackerMilestones::dueDates($project);$this->assertSame('2026-10-19',$dates['contractEndReminderDate']);
        $types=fn($r,$day,$settings=[])=>array_column(TrackerMilestones::alerts($r,$settings,new \DateTimeImmutable($day)),'type');
        $this->assertNotContains('contract-end',$types($project,'2026-10-16'));
        $this->assertContains('contract-end',$types($project,'2026-10-19'));
        $this->assertContains('contract-end-overdue',$types($project,'2026-10-20'));
        $this->assertContains('contract-end',$types($project,'2026-10-12',['contractEnd'=>2]));
        $this->assertNotContains('contract-end-overdue',$types($project+['completion'=>'2026-10-19'],'2026-10-20'));
        $this->assertSame([],$types(array_replace($project,['statusMode'=>'manual','manualStatus'=>'Completed']),'2026-10-20'));
        $this->assertSame([],$types($project+['archivedAt'=>'2026-10-01'],'2026-10-20'));
        $this->assertContains('contract-end',array_column(TrackerMilestones::automaticResolutions($project+['completion'=>'2026-10-19'],new \DateTimeImmutable('2026-10-20')),'type'));
    }
    public function test_invalid_new_percentage_and_identifiers_rejected_but_unchanged_legacy_kept(): void {
        foreach([['physicalAccomplishment'=>'-1'],['physicalAccomplishment'=>'101'],['physicalAccomplishment'=>'unknown'],['resolutionNumber'=>'ABC'],['obreNumber'=>'A-1']]as$record){
            try{TrackerProjectInput::validateDates($record);$this->fail('Invalid revised field accepted.');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        }
        $legacy=['resolutionNumber'=>'ABC','physicalAccomplishment'=>'Unknown','winningBidder'=>'Legacy'];
        TrackerProjectInput::validateDates($legacy,$legacy);$this->assertSame('Legacy',TrackerProjectInput::normalize($legacy)['winningBidder']);
    }
    public function test_export_writes_all_fields_with_typed_percent_currency_dates_and_na(): void {
        $record=['projectNo'=>'1','title'=>'Round trip','referenceNo'=>'00001-A','prNumber'=>'00005','resolutionNumber'=>'00012','obreNumber'=>'00007','approvedBudget'=>'1250.50','physicalAccomplishment'=>'0','contractRef'=>'2026-10-01','statusMode'=>'manual','manualStatus'=>'Pending','status'=>'Pending','ldAmount'=>'N/A','endUserOffice'=>'Engineering','endUserName'=>'Alex','endUserPosition'=>'Engineer'];
        for($i=1;$i<=10;$i++)$record['material'.$i]='Material '.$i;
        $path=FormattedProjectExport::create([$record],public_path('templates/project-export-template.xlsx'));
        try{
            $zip=new \ZipArchive;$zip->open($path);$doc=new \DOMDocument;$doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
            $xp=new \DOMXPath($doc);$xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cells=$xp->query('//x:row[@r="2"]/x:c');$keys=array_keys(TrackerProjectFields::columns());
            $this->assertSame(count($keys),$cells->length);
            $map=[];foreach($keys as$i=>$key)$map[$key]=$cells->item($i);
            foreach(['referenceNo'=>'00001-A','prNumber'=>'00005','obreNumber'=>'00007','material10'=>'Material 10','endUserName'=>'Alex','ldNewCompletionDate'=>'N/A','ldActualDuration'=>'N/A']as$key=>$expected)$this->assertSame($expected,$map[$key]->textContent);
            $this->assertSame('n',$map['physicalAccomplishment']->getAttribute('t'));$this->assertSame('0',$map['physicalAccomplishment']->textContent);
            $this->assertSame('n',$map['approvedBudget']->getAttribute('t'));$this->assertSame('1250.50',$map['approvedBudget']->textContent);
            $this->assertSame('n',$map['contractRef']->getAttribute('t'));$zip->close();
        }finally{@unlink($path);}
    }
}
