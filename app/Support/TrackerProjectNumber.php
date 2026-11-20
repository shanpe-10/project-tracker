<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class TrackerProjectNumber
{
    public static function preview(): array
    {
        $number = TrackerSql::number();
        $summary = DB::table('projects')->selectRaw("COUNT(*) AS total, COUNT(archived_at) AS archived, MAX($number) AS highest, MAX(CASE WHEN archived_at IS NOT NULL THEN $number ELSE 0 END) AS archived_highest")->first();
        $total = (int) $summary->total;
        $archived = (int) $summary->archived;
        return ['nextProjectNumber' => (string) max(1, (int) $summary->highest + 1),
            'projectCount' => $total, 'activeProjectCount' => $total - $archived, 'archivedProjectCount' => $archived,
            'highestArchivedProjectNumber' => (int) $summary->archived_highest > 0 ? (string) $summary->archived_highest : null];
    }

    public static function next(): int
    {
        $highest = (int) DB::table('projects')->whereRaw(TrackerSql::numericPredicate())->max(DB::raw(TrackerSql::number()));
        // Archived projects still reserve their permanent number. Deleted numbers
        // below the highest remaining project stay as gaps; the deleted tail is reusable.
        return max(1, $highest + 1);
    }

    /** Reserve only inside the same transaction that saves the project. */
    public static function allocate(array $reserved = []): string
    {
        return DB::transaction(function () use ($reserved) {
            self::lock();
            $number = self::next();
            while (isset($reserved[(string) $number])) $number++;
            DB::table('project_number_sequences')->where('sequence_name', 'projects')->update(['next_number' => $number + 1]);
            return (string) $number;
        });
    }

    public static function synchronize(): void
    {
        self::lock();
        DB::table('project_number_sequences')->where('sequence_name', 'projects')->update(['next_number' => self::next()]);
    }

    private static function lock(): void
    {
        DB::table('project_number_sequences')->insertOrIgnore(['sequence_name' => 'projects', 'next_number' => 1]);
        DB::table('project_number_sequences')->where('sequence_name', 'projects')->lockForUpdate()->first();
    }
}
