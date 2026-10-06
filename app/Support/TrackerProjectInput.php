<?php
namespace App\Support;
use Illuminate\Validation\ValidationException;
final class TrackerProjectInput
{
    public const DATE_FIELDS = ['biddingDate','prDate','obre','resolutionDate','noticePostQual','noa','performanceBond','contractRef','ntpDate','dole','contractEndDate','preInspection','materialTestingDate','finalInspection','swa','ldNewCompletionDate','completion'];

    public static function normalize(array $record): array
    {
        $days = TrackerMilestones::duration($record['contractDuration'] ?? null);
        if ($days !== null) $record['contractDuration'] = $days;
        if ($days !== null && TrackerMilestones::date($record['contractRef'] ?? null)) {
            $record['contractEndDate'] = TrackerMilestones::dueDates($record)['contractDueDate'];
        }
        return $record;
    }

    public static function validateDates(array $record, array $previous = []): void
    {
        foreach (self::DATE_FIELDS as $key) {
            $value = $record[$key] ?? '';
            // Keep unchanged historical values; reject new invalid calendar dates.
            if ($value === null || $value === '' || $value === '-' || $value === ($previous[$key] ?? null)) continue;
            if (!\App\Http\Controllers\TrackerDataController::validProjectDate($value)) {
                throw ValidationException::withMessages([$key => "Invalid $key date."]);
            }
        }
    }

    public static function validateManualResolution(array $record, array $previous = []): void
    {
        // Preserve historical flags, but do not allow new date-based or global dismissals.
        $completed = is_array($record['timelineAlertsCompleted'] ?? null) ? $record['timelineAlertsCompleted'] : [];
        $old = is_array($previous['timelineAlertsCompleted'] ?? null) ? $previous['timelineAlertsCompleted'] : [];
        foreach (array_diff($completed, $old) as $type) {
            if (! TrackerMilestones::canManuallyResolve(array_replace($previous, $record), $type)) {
                throw ValidationException::withMessages(['timelineAlertsCompleted'=>'Record the actual milestone date to resolve this alert. Mark Done is available only for NOA and legacy NTP reminders.']);
            }
        }
        if (! empty($record['timelineCompletedMarked']) && empty($previous['timelineCompletedMarked'])) {
            throw ValidationException::withMessages(['timelineCompletedMarked'=>'Resolve NOA or legacy NTP reminders individually; record actual dates for other milestones.']);
        }
    }

    public static function validateAlertPermission(array $record, array $previous, bool $allowed): void
    {
        if ($allowed) return;
        $defaults = ['timelineAlertsCompleted'=>[], 'timelineCompletedMarked'=>false, 'timelineMarkedDoneDate'=>null, 'timelineUnmarkedType'=>null, 'timelineUnmarkedAt'=>null];
        foreach ($defaults as $key=>$default) {
            if (($record[$key] ?? $default) !== ($previous[$key] ?? $default)) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['ok'=>false,'message'=>'Alert permission is required to mark or restore alerts.'],403));
            }
        }
    }

    public static function validate(array $record): void
    {
        if (isset($record['id']) && filter_var($record['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) === false) throw ValidationException::withMessages(['id'=>'Invalid record ID.']);
        foreach (['biddingDate','prDate','obre','resolutionDate','noticePostQual','noa','performanceBond','contractRef','ntpDate','dole','contractEndDate','preInspection','materialTestingDate','finalInspection','swa','ldNewCompletionDate','completion','updatedAt'] as $key) {
            if (isset($record[$key]) && !is_string($record[$key])) throw ValidationException::withMessages([$key=>"$key must be a date string."]);
        }
        $duration = $record['contractDuration'] ?? null;
        if ($duration !== null && $duration !== '' && TrackerMilestones::duration($duration) === null) {
            throw ValidationException::withMessages(['contractDuration'=>'Contract duration must be a positive numeric value in calendar days (up to 36500).']);
        }
        $limits = ['projectNo'=>60,'title'=>255,'status'=>100,'winningBidder'=>255,'sourceOfFund'=>160,'prNumber'=>120];
        foreach ($limits as $key=>$max) {
            if (isset($record[$key]) && (!is_scalar($record[$key]) || mb_strlen(trim((string)$record[$key])) > $max)) {
                throw ValidationException::withMessages([$key=>"$key must be text of up to $max characters."]);
            }
        }
        foreach (['approvedBudget','bidAmount'] as $key) {
            if (!isset($record[$key]) || $record[$key] === '' || $record[$key] === '-') continue;
            if (!is_scalar($record[$key])) throw ValidationException::withMessages([$key=>"Invalid $key amount."]);
            $value=preg_replace('/[₱$,\s]/u','',(string)$record[$key]);
            if (!preg_match('/^\d+(\.\d{1,2})?$/D',$value) || strlen(ltrim(explode('.',$value)[0],'0')) > 13) {
                throw ValidationException::withMessages([$key=>"$key must be a non-negative amount with up to 13 integer digits and two decimal places."]);
            }
        }
        foreach (['resolutionNumber','contractDuration','ldAmount','ldNoOfDays','ldActualDuration','remarks','createdAt','archivedAt','archivedBy','updatedBy','timelineMarkedDoneDate','timelineUnmarkedType','timelineUnmarkedAt'] as $key) {
            if (isset($record[$key]) && !is_scalar($record[$key])) throw ValidationException::withMessages([$key=>"$key must be text."]);
        }
        foreach (['attachments','timelineAlertsCompleted'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key])) throw ValidationException::withMessages([$key=>"$key must be a list."]);
        }
        if (isset($record['timelineAlertsCompleted'])) {
            foreach ($record['timelineAlertsCompleted'] as $type) {
                if (!is_string($type)) throw ValidationException::withMessages(['timelineAlertsCompleted'=>'Alert types must be text.']);
            }
        }
        if (isset($record['timelineCompletedMarked']) && !is_bool($record['timelineCompletedMarked'])) throw ValidationException::withMessages(['timelineCompletedMarked'=>'The timeline marker must be true or false.']);
    }
}
