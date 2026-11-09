<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Sources\PublicWebsiteConnector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('melectric:crawl-public {--max=20 : Páginas como máximo}')]
#[Description('Sincroniza las páginas públicas de melectric.com.ar (respeta robots, no entra a zonas con login)')]
class CrawlPublicCommand extends Command
{
    public function handle(PublicWebsiteConnector $connector): int
    {
        $stats = $connector->crawl(maxPages: (int) $this->option('max'));
        $this->table(['Métrica', 'Valor'], collect($stats)->except(['errors', 'out_of_scope'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($stats['out_of_scope'] as $o) {
            $this->line('fuera de alcance: '.$o);
        }
        foreach ($stats['errors'] as $e) {
            $this->warn($e);
        }

        return self::SUCCESS;
    }
}
