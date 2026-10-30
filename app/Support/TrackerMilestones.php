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

    public static function isWorkday(\DateTimeImmutable $date): bool
    {
        $calendar = TrackerHolidays::calendar();
        return ! in_array((int)$date->format('w'), $calendar['nonWorkingDays'], true)
            && (! $calendar['excludeHolidays'] || ! isset($calendar['years'][$date->format('Y')][$date->format('Y-m-d')]));
    }

    public static function weekday(?\DateTimeImmutable $date): ?\DateTimeImmutable
    {
        if (! $date) return null;
        while (! self::isWorkday($date)) $date = $date->modify('+1 day');
        return $date;
    }

    public static function addWorkdays(\DateTimeImmutable $date, float $days): \DateTimeImmutable
    {
        $direction = $days < 0 ? -1 : 1;
        $whole = (int) floor(abs($days));
        for ($i = 0; $i < $whole; $i++) {
            do { $date = $date->modify($direction > 0 ? '+1 day' : '-1 day'); }
            while (! self::isWorkday($date));
        }
        $date = self::weekday($date);
        $seconds = (int) round((abs($days) - $whole) * 86400) * $direction;
        return self::weekday($date->modify(($seconds >= 0 ? '+' : '').$seconds.' seconds'));
    }

    public static function dueDates(array $project): array
    {
        $bidding = self::date($project['biddingDate'] ?? null);
        $biddingDue = null;
        if ($bidding) {
            $nextMonth = $bidding->modify('first day of next month');
            $day = min((int) $bidding->format('j'), (int) $nextMonth->format('t'));
            $biddingDue = self::weekday($nextMonth->modify('+'.($day - 1).' days'))->format('Y-m-d');
        }
        $start = self::date($project['contractRef'] ?? null);
        $days = self::duration($project['contractDuration'] ?? null);
        $end = $start && $days !== null
            ? self::addWorkdays($start, $days)
            : self::weekday(self::date($project['contractEndDate'] ?? null));
        return ['noaDueDate'=>self::weekday(self::date($project['noaDueDate'] ?? null))?->format('Y-m-d'), 'preInspectionDueDate'=>$biddingDue, 'ntpDueDate'=>$biddingDue, 'materialTestingDueDate'=>$biddingDue, 'contractDueDate'=>$end?->format('Y-m-d'), 'finalInspectionDueDate'=>$end?->format('Y-m-d')];
    }

    public static function alerts(array $project, array $settings, \DateTimeImmutable $today): array
    {
        if (! empty($project['archivedAt']) || ! empty($project['timelineCompletedMarked']) || in_array(strtolower(TrackerProjectInput::automaticStatus($project,$today)), ['completed','released'], true)) return [];
        $dueDates = self::dueDates($project);
        $resolved = is_array($project['timelineAlertsCompleted'] ?? null) ? $project['timelineAlertsCompleted'] : [];
        $today = $today->format('Y-m-d');
        $alerts = [];
        $append = function (string $field, string $actualField, string $type, string $overdueType, string $label, int $lead) use ($project, $dueDates, $resolved, $today, &$alerts): void {
            $due = self::date($dueDates[$field]);
            $actual = $actualField !== '' ? self::date($project[$actualField] ?? null) : null;
            if (! $due || ($actual && $actual->format('Y-m-d') <= $today) || in_array($type,$resolved,true) || in_array($overdueType,$resolved,true)) return;
            if ($today < self::addWorkdays($due, -$lead)->format('Y-m-d')) return;
            $overdue = $today > $due->format('Y-m-d');
            $alerts[] = ['type'=>$overdue ? $overdueType : $type, 'label'=>$label.($overdue ? ' Overdue' : ' Due'), 'dueDate'=>$due->format('Y-m-d'), 'project'=>$project];
        };
        $append('noaDueDate','noa','noa-due','noa-due-overdue','NOA',(int) ($settings['noa'] ?? 10));
        $append('preInspectionDueDate','preInspection','pre-inspection','pre-inspection-overdue','Pre-inspection',(int) ($settings['bidding'] ?? $settings['material'] ?? 15));
        $append('ntpDueDate','ntpDate','ntp-due','ntp-due-overdue','Notice to Proceed',(int) ($settings['bidding'] ?? $settings['material'] ?? 15));
        $append('materialTestingDueDate','materialTestingDate','material-test','material-overdue','Material Testing',(int) ($settings['bidding'] ?? $settings['material'] ?? 15));
        $append('finalInspectionDueDate','finalInspection','final-inspection','final-inspection-overdue','Final Inspection',(int) ($settings['finalInspection'] ?? 7));
        return $alerts;
    }

    public static function automaticResolutions(array $project, \DateTimeImmutable $today): array
    {
        $dates = self::dueDates($project);
        $definitions = [
            ['noa',self::date($project['noaDueDate'] ?? null) ? 'noa-due' : 'noa-active',['noa-warning','noa-active','noa-overdue','noa-due','noa-due-overdue']],
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
