<?php

namespace App\Support;

/** Derived deadlines never replace the actual milestone dates in project data. */
final class TrackerMilestones
{
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || substr($value, 0, 4) === '0000') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    public static function duration(mixed $value): ?float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) return null;
        $days = (float) $value;
        return is_finite($days) && $days > 0 && $days <= 36500 ? $days : null;
    }

    public static function dueDates(array $project): array
    {
        $bidding = self::date($project['biddingDate'] ?? null);
        $biddingDue = null;
        if ($bidding) {
            $nextMonth = $bidding->modify('first day of next month');
            $day = min((int) $bidding->format('j'), (int) $nextMonth->format('t'));
            $biddingDue = $nextMonth->modify('+'.($day - 1).' days')->format('Y-m-d');
        }
        $start = self::date($project['contractRef'] ?? null);
        $days = self::duration($project['contractDuration'] ?? null);
        $end = $start && $days !== null
            ? $start->modify('+'.(int) round($days * 86400).' seconds')
            : self::date($project['contractEndDate'] ?? null);
        return ['preInspectionDueDate'=>$biddingDue, 'ntpDueDate'=>$biddingDue, 'materialTestingDueDate'=>$biddingDue, 'contractDueDate'=>$end?->format('Y-m-d'), 'finalInspectionDueDate'=>$end?->format('Y-m-d')];
    }

    public static function alerts(array $project, array $settings, \DateTimeImmutable $today): array
    {
        if (! empty($project['archivedAt']) || ! empty($project['timelineCompletedMarked']) || in_array(strtolower(trim((string) ($project['status'] ?? ''))), ['completed','released'], true)) return [];
        $dueDates = self::dueDates($project);
        $resolved = is_array($project['timelineAlertsCompleted'] ?? null) ? $project['timelineAlertsCompleted'] : [];
        $today = $today->format('Y-m-d');
        $alerts = [];
        $append = function (string $field, string $actualField, string $type, string $overdueType, string $label, int $lead) use ($project, $dueDates, $resolved, $today, &$alerts): void {
            $due = self::date($dueDates[$field]);
            $actual = $actualField !== '' ? self::date($project[$actualField] ?? null) : null;
            if (! $due || ($actual && $actual->format('Y-m-d') <= $today) || in_array($type,$resolved,true) || in_array($overdueType,$resolved,true)) return;
            if ($today < $due->modify('-'.$lead.' days')->format('Y-m-d')) return;
            $overdue = $today > $due->format('Y-m-d');
            $alerts[] = ['type'=>$overdue ? $overdueType : $type, 'label'=>$label.($overdue ? ' Overdue' : ' Due'), 'dueDate'=>$due->format('Y-m-d'), 'project'=>$project];
        };
        $append('preInspectionDueDate','preInspection','pre-inspection','pre-inspection-overdue','Pre-inspection',(int) ($settings['bidding'] ?? $settings['material'] ?? 15));
        $append('ntpDueDate','ntpDate','ntp-due','ntp-due-overdue','Notice to Proceed',(int) ($settings['bidding'] ?? $settings['material'] ?? 15));
        $append('materialTestingDueDate','materialTestingDate','material-test','material-overdue','Material Testing',(int) ($settings['bidding'] ?? $settings['material'] ?? 15));
        $append('finalInspectionDueDate','finalInspection','final-inspection','final-inspection-overdue','Final Inspection',(int) ($settings['finalInspection'] ?? 7));
        return $alerts;
    }

    public static function canManuallyResolve(array $project, string $type): bool
    {
        if (in_array($type, ['noa-warning','noa-active','noa-overdue'], true)) return true;
        return in_array($type, ['ntp-award','ntp-overdue'], true) && ! self::date($project['biddingDate'] ?? null);
    }

    public static function automaticResolutions(array $project, \DateTimeImmutable $today): array
    {
        $dates = self::dueDates($project);
        $definitions = [
            ['noa','noa-active',['noa-warning','noa-active','noa-overdue']],
            ['preInspection','pre-inspection',['pre-inspection','pre-inspection-overdue']],
            ['ntpDate','ntp-due',['ntp-due','ntp-due-overdue','ntp-award','ntp-overdue']],
            ['materialTestingDate','material-test',['material-test','material-overdue']],
            ['finalInspection','final-inspection',['final-inspection','final-inspection-overdue']],
            ['swa','award-overdue',['award-close','award-overdue']],
        ];
        $resolved = [];
        foreach ($definitions as [$field, $type, $aliases]) {
            $date = self::date($project[$field] ?? null);
            if ($date && $date->format('Y-m-d') <= $today->format('Y-m-d')) {
                $resolved[] = ['type'=>$type,'aliases'=>$aliases,'doneDate'=>$date->format('Y-m-d')];
            }
        }
        return $resolved;
    }
}
