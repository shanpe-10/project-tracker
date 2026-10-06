<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
final class TrackerSql
{
    private static function sqlite(): bool { return DB::connection()->getDriverName() === 'sqlite'; }
    public static function numericPredicate(): string {
        return self::sqlite() ? "project_no REGEXP '^[0-9]+$'" : 'project_no COLLATE "C" ~ \'^[0-9]+$\'';
    }
    public static function number(): string {
        return 'CASE WHEN '.self::numericPredicate().' THEN '.(self::sqlite() ? 'CAST(project_no AS NUMERIC)' : 'project_no::numeric').' ELSE 0 END';
    }
    public static function order(string $direction = 'ASC'): string {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        // Match MariaDB NULL ordering and avoid casting alphanumeric numbers.
        return 'CASE WHEN '.self::numericPredicate()." THEN 0 ELSE 1 END $direction, ".self::number()." $direction, project_no $direction".(self::sqlite() ? '' : ($direction === 'ASC' ? ' NULLS FIRST' : ' NULLS LAST')).", id $direction";
    }
    public static function date(string $key): string {
        if (!preg_match('/^[A-Za-z]+$/D', $key)) throw new \InvalidArgumentException('Invalid date key');
        return self::sqlite() ? "date(data->>'$key')" : "tracker_date(data->>'$key')";
    }
    public static function datePart(string $part, string $expression): string {
        $formats = ['month' => '%m', 'day' => '%d', 'year' => '%Y'];
        if (!isset($formats[$part])) throw new \InvalidArgumentException('Invalid date part');
        return self::sqlite() ? "CAST(strftime('{$formats[$part]}', $expression) AS INTEGER)" : 'EXTRACT('.strtoupper($part)." FROM $expression)";
    }
    public static function barangay(): string {
        return self::sqlite() ? "LOWER(CONCAT_WS(' ', title, data->>'remarks')) REGEXP ?" : 'LOWER(CONCAT_WS(\' \', title, data->>\'remarks\')) COLLATE "C" ~ ?';
    }
}
