<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

final class TrackerHolidays
{
    private const FEED = 'https://pco.gov.ph/category/holidays/feed/?post_type=news_releases';
    private static ?array $cachedCalendar = null;
    private static ?string $cachedPath = null;

    private static function path(): string
    {
        return config('tracker_holidays.state_path', storage_path('app/holiday-sync.json'));
    }

    public static function state(): array
    {
        if (config('tracker.cloud')) {
            $saved = DB::table('application_settings')->where('setting_key', 'cloud_holiday_calendar')->value('setting_value');
            if ($saved !== null) return json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
        }
        if (config('tracker.cloud') || ! is_file(self::path())) return ['enabled'=>true, 'revision'=>0, 'overrides'=>[], 'years'=>[], 'sources'=>[], 'review'=>[], 'lastAttempt'=>null, 'lastSuccess'=>null, 'error'=>null];
        return json_decode(file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function calendar(): array
    {
        $cachePath = self::path().'|'.public_path().'|'.(config('tracker.cloud') ? 'cloud' : 'local');
        if (self::$cachedCalendar !== null && self::$cachedPath === $cachePath) return self::$cachedCalendar;
        $calendar = json_decode(file_get_contents(public_path('data/philippine-holidays.json')), true, 512, JSON_THROW_ON_ERROR);
        $state = self::state();
        foreach ($state['years'] as $year=>$dates) $calendar['years'][$year] = array_replace($calendar['years'][$year] ?? [], $dates);
        foreach ($state['removed'] ?? [] as $date) unset($calendar['years'][substr($date,0,4)][$date]);
        foreach ($state['overrides'] as $date=>$entry) {
            $year = substr($date, 0, 4);
            if ($entry['nonWorking']) $calendar['years'][$year][$date] = $entry['name'];
            else unset($calendar['years'][$year][$date]);
        }
        foreach ($calendar['years'] as &$dates) ksort($dates);
        unset($dates);
        ksort($calendar['years']);
        $calendar['nonWorkingDays'] = $state['nonWorkingDays'] ?? [0,6];
        $calendar['excludeHolidays'] = $state['excludeHolidays'] ?? true;
        $calendar['sources'] = array_values(array_unique(array_merge($calendar['sources'], $state['sources'])));
        foreach ($calendar['pending'] ?? [] as $year=>$pending) {
            $names = implode(' ', $calendar['years'][$year] ?? []);
            $calendar['pending'][$year] = array_values(array_filter($pending, fn ($name)=>! str_contains($names, $name)));
        }
        self::$cachedPath = $cachePath;
        return self::$cachedCalendar = $calendar;
    }

    public static function status(): array
    {
        return ['ok'=>true, 'state'=>self::state(), 'calendar'=>self::calendar(), 'source'=>'Presidential Communications Office', 'schedule'=>'Daily at 03:00 Philippine time; retries hourly after a failure.'];
    }

    private static function mutate(callable $change): array
    {
        if (config('tracker.cloud')) {
            return DB::transaction(function () use ($change) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tracker:cloud-holidays']);
                $state = $change(self::state());
                $state['revision']++;
                DB::table('application_settings')->updateOrInsert(['setting_key'=>'cloud_holiday_calendar'],
                    ['setting_value'=>json_encode($state, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                self::$cachedCalendar = null;
                return $state;
            });
        }
        $directory = dirname(self::path());
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) throw new \RuntimeException('The holiday calendar directory is unavailable.');
        $lock = fopen(self::path().'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) throw new \RuntimeException('Could not lock the holiday calendar.');
        try {
            $state = $change(self::state());
            $state['revision']++;
            $temporary = tempnam($directory, 'holiday-');
            try {
                if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) === false || ! rename($temporary, self::path())) throw new \RuntimeException('Could not save the holiday calendar.');
            } finally { if (is_file($temporary)) unlink($temporary); }
            self::$cachedCalendar = null;
            return $state;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function update(int $revision, callable $change): array
    {
        return self::mutate(function ($state) use ($revision, $change) {
            if ($state['revision'] !== $revision) throw new \DomainException('The calendar changed. Refresh it before saving.');
            return $change($state);
        });
    }

    private static function fetch(string $url): string
    {
        // Only fixed official hosts and article paths are requested; redirects cannot leave the allowlist.
        if (! preg_match('~^https://pco\.gov\.ph/(?:category/holidays/feed/\?|news_releases/[a-z0-9-]+/\z)~D', $url)) throw new \RuntimeException('Unsupported holiday source.');
        $response = Http::connectTimeout(5)->timeout(12)->withOptions(['allow_redirects'=>false])->withHeaders(['User-Agent'=>'LGU-Project-Tracker-Holiday-Sync/1.0', 'Accept'=>'application/rss+xml,text/html'])->get($url);
        $response->throw();
        if ($response->status() !== 200 || strlen($response->body()) > 2000000) throw new \RuntimeException('The official source returned an unsupported response.');
        return $response->body();
    }

    public static function parseArticle(string $html, string $title, int $publishedYear): array
    {
        // PCO currently labels UTF-8 pages as ISO-8859-1; honor the actual byte encoding.
        if (mb_check_encoding($html, 'UTF-8')) $html = preg_replace('/charset\s*=\s*["\x27]?[a-z0-9-]+/i', 'charset=UTF-8', $html);
        $doc = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
        $xpath = new \DOMXPath($doc);
        $root = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," entry-content ")]')->item(0);
        if (! $root) return [];
        $paragraphs = [];
        foreach ($xpath->query('.//p', $root) as $p) $paragraphs[] = trim(preg_replace('/\s+/u', ' ', $p->textContent));
        $text = implode(' ', $paragraphs);
        $annual = preg_match('/(?:declares|declared).*\b(20\d{2})\b.*regular.*(?:special|holidays)/i', $title, $match);
        $year = $annual ? (int) $match[1] : $publishedYear;
        if ($year < 2020 || $year > $publishedYear + 2) return [];
        $dates = [];
        if ($annual) {
            // Accept the official English list only when all three categories are present.
            if (! str_contains(strtolower($text), 'regular holidays') || ! str_contains(strtolower($text), 'special non-working') || ! str_contains(strtolower($text), 'additional special')) return [];
            foreach ($paragraphs as $p) {
                if (! preg_match('/regular holidays (?:are|include)|special (?:\(non-working\)|non-working) days/i', $p) || preg_match('/special working day/i', $p)) continue;
                $p = preg_replace('/^.*?(?:regular holidays (?:are|include)|also declares|designated)\s+/iu', '', $p);
                preg_match_all('/(?:^|[;,])\s*(?:and )?([^;,]+?) on ((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?)\s+(\d{1,2})\b/iu', $p, $items, PREG_SET_ORDER);
                foreach ($items as $item) {
                    $date = self::parseDate($item[2], $item[3], $year);
                    if ($date) $dates[$date] = trim($item[1]);
                }
            }
            return count($dates) >= 14 && count($dates) <= 30 ? $dates : [];
        }
        // Local declarations and estimated/recommended dates never become national exclusions.
        foreach ($paragraphs as $p) {
            if (! preg_match('/(?:declared?|declaring|proclamation)/i', $p) || ! preg_match('/(?:regular holiday|special (?:\(non-working\)|non-working) (?:day|holiday))/i', $p) || ! preg_match('/throughout (?:the )?country|nationwide/i', $p)) continue;
            if (preg_match('/recommended|may be|expected|tentative|estimated|cancelled|revoked/i', $p)) continue;
            preg_match_all('/\b(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(20\d{2})\b|\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:,)?\s+(20\d{2})\b/i', $p, $items, PREG_SET_ORDER);
            foreach ($items as $item) {
                $date = ! empty($item[1]) ? self::parseDate($item[2], $item[1], (int)$item[3]) : self::parseDate($item[4], $item[5], (int)$item[6]);
                if ($date) {
                    $name = preg_match('/Eid[’\x27]?l?\s*Fitr|Eidul Fitr/iu', $title) ? 'Eid’l Fitr' : (preg_match('/Eid[’\x27]?l?\s*Adha|Eidul Adha/iu', $title) ? 'Eid’l Adha' : $title);
                    $dates[$date] = $name;
                }
            }
        }
        return count($dates) === 1 ? $dates : [];
    }

    private static function parseDate(string $month, string $day, int $year): ?string
    {
        $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        $number = $months[strtolower(substr($month, 0, 3))] ?? 0;
        return checkdate($number, (int)$day, $year) ? sprintf('%04d-%02d-%02d', $year, $number, $day) : null;
    }

    public static function sync(bool $force = false): array
    {
        $state = self::state();
        if (! $force && (! $state['enabled'] || ($state['lastSuccess'] && substr($state['lastSuccess'],0,10) === now('Asia/Manila')->format('Y-m-d')))) return self::status();
        $directory = dirname(self::path());
        if (! is_dir($directory)) mkdir($directory, 0750, true);
        $lock = fopen(self::path().'.sync-lock', 'c');
        if (! $lock) throw new \RuntimeException('Could not open the sync lock.');
        if (! flock($lock, LOCK_EX|LOCK_NB)) { fclose($lock); throw new \DomainException('A holiday sync is already running.'); }
        $attempt = now('Asia/Manila')->toIso8601String();
        try {
            $years = []; $sources = []; $review = []; $seen = []; $seenNames = [];
            for ($page = 1; $page <= 6; $page++) {
                $xmlText = self::fetch(self::FEED.($page === 1 ? '' : '&paged='.$page));
                $prior = libxml_use_internal_errors(true);
                try { $xml = simplexml_load_string(ltrim($xmlText, "\xEF\xBB\xBF \t\r\n"), \SimpleXMLElement::class, LIBXML_NONET); }
                finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
                if (! $xml || ! isset($xml->channel)) throw new \RuntimeException('The official holiday feed could not be read.');
                if (! count($xml->channel->item)) break;
                $fresh = 0;
                foreach ($xml->channel->item as $item) {
                    $title = (string)$item->title; $url = (string)$item->link;
                    if (isset($seen[$url])) continue;
                    $seen[$url] = true; $fresh++;
                    $annual = preg_match('/declares.*20\d{2}.*regular.*(?:special|holidays)/i', $title);
                    $candidate = $annual || preg_match('/Eid|nationwide|throughout.*country|national.*holiday|regular holiday/i', $title);
                    if (! $candidate || ! preg_match('~^https://pco\.gov\.ph/news_releases/[a-z0-9-]+/$~D', $url)) continue;
                    $publishedYear = (int) date('Y', strtotime((string)$item->pubDate));
                    $dates = self::parseArticle(self::fetch($url), $title, $publishedYear);
                    if (! $dates) { $review[] = ['title'=>$title,'url'=>$url]; continue; }
                    foreach ($dates as $date=>$name) {
                        // Feed is newest first: older articles cannot overwrite a newer declaration.
                        $year = substr($date,0,4);
                        if (! isset($seenNames[$year][$name]) && ! isset($years[$year][$date])) { $years[$year][$date] = $name; $seenNames[$year][$name] = true; }
                    }
                    $sources[] = $url;
                }
                if (! $fresh) break;
            }
            self::mutate(function ($state) use ($years, $sources, $review, $attempt) {
                $existing = self::calendar()['years'];
                $removed = array_fill_keys($state['removed'] ?? [], true);
                foreach ($years as $year=>$dates) {
                    foreach ($dates as $date=>$name) {
                        foreach ($existing[$year] ?? [] as $oldDate=>$oldName) if ($oldName === $name && $oldDate !== $date) $removed[$oldDate] = true;
                        unset($removed[$date]);
                    }
                    $state['years'][$year] = array_replace($state['years'][$year] ?? [], $dates);
                }
                $state['removed'] = array_keys($removed);
                $state['sources'] = array_values(array_unique(array_merge($state['sources'], $sources)));
                $state['review'] = $review; $state['lastAttempt'] = $attempt; $state['lastSuccess'] = $attempt; $state['error'] = null;
                return $state;
            });
            return self::status();
        } catch (\Throwable $error) {
            self::mutate(function ($state) use ($attempt) { $state['lastAttempt']=$attempt; $state['error']='The official source could not be synced. The last saved calendar is still active. Try again when internet access is available.'; return $state; });
            report($error);
            throw new \RuntimeException('Holiday sync failed. The last saved calendar is still active.', 0, $error);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
