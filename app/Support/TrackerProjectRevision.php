<?php
namespace App\Support;

use Illuminate\Validation\ValidationException;

final class TrackerProjectRevision
{
    public const STATUSES = ['On-going Bidding','Pre-inspection','Pending','Final-inspection','Completed','Released'];

    public static function normalizeFields(array $record): array
    {
        if (isset($record['physicalAccomplishment'])) {
            $value = trim((string)$record['physicalAccomplishment']);
            if (strtoupper($value) === 'NYS') $value = '0';
            elseif (str_ends_with($value, '%')) $value = trim(substr($value, 0, -1));
            if ($value !== '' && is_numeric($value)) $value = (string)(0 + $value);
            $record['physicalAccomplishment'] = $value;
        }
        if (isset($record['statusMode'])) {
            $mode = strtolower(trim((string)$record['statusMode']));
            $record['statusMode'] = $mode === 'auto' ? 'automatic' : $mode;
        }
        foreach (['status','manualStatus'] as $key) {
            if (!isset($record[$key])) continue;
            $candidate = strtolower(trim((string)$record[$key]));
            foreach (self::STATUSES as $status) {
                if (str_replace(['-',' '], '', strtolower($status)) === str_replace(['-',' '], '', $candidate)) $record[$key] = $status;
            }
        }
        if (($record['statusMode'] ?? '') === 'manual' && empty($record['manualStatus']) && !empty($record['status'])) $record['manualStatus'] = $record['status'];
        if (($record['statusMode'] ?? '') === 'automatic') $record['manualStatus'] = null;
        $record['ldNotApplicable'] = TrackerProjectFields::isNotApplicable($record);
        if ($record['ldNotApplicable']) {
            $record['ldAmount'] = $record['ldNoOfDays'] = $record['ldRemarks'] = 'N/A';
            $record['ldNewCompletionDate'] = $record['ldActualDuration'] = null;
        }
        return $record;
    }

    public static function validateFields(array $record, array $previous = []): void
    {
        $record = self::normalizeFields($record);
        foreach (TrackerProjectFields::fields() as $field) {
            $key = $field['key']; $value = $record[$key] ?? '';
            if ($value === null || $value === '') continue;
            if (!is_string($value) && !is_int($value) && !is_float($value)) throw ValidationException::withMessages([$key=>$field['label'].' must be a single value.']);
            if (mb_strlen((string)$value) > $field['max']) throw ValidationException::withMessages([$key=>$field['label'].' is too long.']);
            if ($value === ($previous[$key] ?? null)) continue;
            if ($field['type'] === 'digits' && !preg_match('/^\d+$/D', (string)$value)) throw ValidationException::withMessages([$key=>$field['label'].' must contain digits only.']);
            if ($field['type'] === 'percentage' && (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 100)) {
                throw ValidationException::withMessages([$key=>'Physical Accomplishment must be a percentage from 0 to 100.']);
            }
        }
        if (isset($record['statusMode']) && !in_array($record['statusMode'], ['automatic','manual'], true)) throw ValidationException::withMessages(['statusMode'=>'Choose Automatic or Manual status mode.']);
        if (($record['statusMode'] ?? '') === 'manual' && !in_array($record['manualStatus'] ?? null, self::STATUSES, true)) throw ValidationException::withMessages(['manualStatus'=>'Choose a valid manual operational status.']);
    }

    public static function exportRecord(array $record): array
    {
        $record = self::normalizeFields($record);
        if (!isset($record['statusMode'])) {
            $undated = (strtolower($record['status'] ?? '') === 'completed' && !TrackerMilestones::date($record['completion'] ?? null))
                || (strtolower($record['status'] ?? '') === 'released' && !TrackerMilestones::date($record['swa'] ?? null));
            $record['statusMode'] = $undated ? 'manual' : 'automatic';
            $record['manualStatus'] = $undated ? $record['status'] : null;
        }
        return $record;
    }
}
