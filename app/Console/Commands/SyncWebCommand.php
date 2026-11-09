<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Sources\WebSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('melectric:sync-web {--base= : URL base (por defecto SYNC_BASE_URL)}')]
#[Description('Sincroniza el sitio web con el script Python e incorpora páginas, PDF y productos publicados')]
class SyncWebCommand extends Command
{
    public function handle(WebSyncService $sync): int
    {
        $run = $sync->run($sync->start($this->option('base'), null));
        foreach ($run->log ?? [] as $l) {
            $this->line(sprintf('[%s] %s: %s', $l['status'], $l['step'], $l['detail']));
        }
        $this->info("Sincronización #{$run->id}: {$run->status}");

        return $run->status === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
