<?php

declare(strict_types=1);

namespace App\Sources;

use App\Documents\DocumentIngestionService;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Http;

/**
 * Conector público de melectric.com.ar (spec §52-55): robots.txt, lista blanca de host, User-Agent propio, 1 pedido por
 * segundo, reintentos con espera, timeouts, sin seguir redirecciones a otro host, detección de login (no se evade),
 * deduplicación por hash. Si falla, los datos ya guardados no se tocan.
 */
final class PublicWebsiteConnector
{
    public const SEEDS = ['/', '/Home/Nosotros', '/Home/Faq', '/Home/Distribuidores', '/Blog'];

    private const SKIP = '#^/(Galeria|Seguridad|Content|Scripts|assets|cdn-cgi|Home/Catalogos)#i';

    public function __construct(private readonly DocumentIngestionService $ingestion) {}

    /** @return array{processed:int, new:int, updated:int, unchanged:int, failed:int, out_of_scope:list<string>, errors:list<string>} */
    public function crawl(string $base = 'https://www.melectric.com.ar', int $maxPages = 20): array
    {
        $cfg = config('melectric.crawler');
        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'out_of_scope' => [], 'errors' => []];
        $disallow = $this->robots($base);
        $queue = self::SEEDS;
        $seen = [];
        while ($queue && $stats['processed'] < $maxPages) {
            $path = array_shift($queue);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            if ($this->blocked($path, $disallow) || preg_match(self::SKIP, $path)) {
                $stats['out_of_scope'][] = $path.' (robots o fuera de alcance)';

                continue;
            }
            $url = rtrim($base, '/').$path;
            try {
                UrlGuard::assertSafe($url, $cfg['allowed_hosts']);
                usleep($cfg['delay_ms'] * 1000);
                $res = Http::withUserAgent($cfg['user_agent'])->timeout(20)->retry(2, 1500, throw: false)->withoutRedirecting()->get($url);
                if ($res->redirect()) {
                    $to = (string) $res->header('Location');
                    $stats['out_of_scope'][] = $path.' → '.$to.(str_contains($to, 'ReturnUrl') ? ' (requiere login: no se accede)' : '');

                    continue;
                }
                if (! $res->successful() || ! str_contains((string) $res->header('Content-Type'), 'text/html')) {
                    $stats['failed']++;
                    $stats['errors'][] = "{$path}: HTTP {$res->status()}";

                    continue;
                }
                $html = $res->body();
                $stats['processed']++;
                $result = $this->ingestion->ingestWebPage($url, $html);
                $stats[$result]++;
                foreach ($this->links($html) as $link) {
                    if (! isset($seen[$link])) {
                        $queue[] = $link;
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "{$path}: ".$e->getMessage();
            }
        }
        AuditLog::record('crawler.public_sync', 'PublicWebsiteConnector', null, collect($stats)->except('errors')->all());

        return $stats;
    }

    /** @return list<string> rutas Disallow que aplican a nuestro agente o a * */
    private function robots(string $base): array
    {
        $res = Http::withUserAgent(config('melectric.crawler.user_agent'))->timeout(10)->get(rtrim($base, '/').'/robots.txt');
        if (! $res->successful()) {
            return [];
        }

        return self::parseRobots($res->body(), 'MElectricAI-Crawler');
    }

    /** @return list<string> */
    public static function parseRobots(string $robots, string $agent): array
    {
        $rules = [];
        $applies = false;
        $lastWasAgent = false;
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if (! preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            if ($key === 'user-agent') {
                $ua = trim($m[2]);
                $match = $ua === '*' || stripos($agent, $ua) !== false;
                $applies = $lastWasAgent ? ($applies || $match) : $match;
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;
            if ($applies && $key === 'disallow' && trim($m[2]) !== '') {
                $rules[] = trim($m[2]);
            }
        }

        return $rules;
    }

    private function blocked(string $path, array $disallow): bool
    {
        foreach ($disallow as $rule) {
            if (str_starts_with($path, $rule)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> rutas internas HTML */
    private function links(string $html): array
    {
        preg_match_all('/href="(\/[A-Za-z][^"#?]*)"/', $html, $m);

        return array_values(array_unique(array_filter($m[1], fn ($p) => ! preg_match('/\.(pdf|jpg|png|css|js|ico)$/i', $p) && ! preg_match(self::SKIP, $p))));
    }
}
