<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Throwable;

/** Safety net for missed webhooks: poll anything that's been queued/processing too long. */
#[AsCommand(name: 'generations:reconcile')]
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile {--stale=45 : Seconds without an update before we poll}';

    protected $description = 'Poll Pixelforge for generations stuck in queued/processing (missed webhooks).';

    public function handle(PixelforgeClient $client, GenerationStateMachine $machine): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds((int) $this->option('stale')))
            ->orderBy('updated_at')
            ->limit(50)
            ->get();

        foreach ($stale as $generation) {
            try {
                $remote = $client->getGeneration($generation->pixelforge_id);
                $after = $machine->apply($remote);
                $this->line("{$generation->id}: {$generation->status->value} -> {$after?->status->value}");

                // Touch so we don't re-poll the same still-running job every single tick.
                if ($after && ! $after->isTerminal()) {
                    $after->touch();
                }
            } catch (Throwable $e) {
                $this->warn("{$generation->id}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
