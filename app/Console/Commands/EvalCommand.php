<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\AI\EvaluationRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('melectric:eval {--llm : Usar el LLM redactor} {--prompt= : Versión de prompt a evaluar}')]
#[Description('Corre el dataset dorado del asistente y muestra las métricas de abstención y respuestas falsas')]
class EvalCommand extends Command
{
    public function handle(EvaluationRunner $runner): int
    {
        $run = $runner->run($this->option('prompt') ? (int) $this->option('prompt') : null, null, (bool) $this->option('llm'));
        $this->table(['Caso', 'Dataset', 'Esperado', 'Obtenido', 'Conf.', 'OK', 'Fallas'], collect($run->results)->map(fn ($r) => [
            $r['id'], $r['dataset'], $r['expected'] ?? '-', $r['outcome'], $r['confidence'], $r['passed'] ? 'sí' : 'NO', implode('; ', $r['failures'])])->all());
        $this->table(['Métrica', 'Valor'], collect($run->metrics)->except(['by_dataset'])->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'sí' : 'no') : $v])->values()->all());

        return $run->metrics['passed'] === $run->metrics['cases'] ? self::SUCCESS : self::FAILURE;
    }
}
