<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FinancialReportController extends Controller
{
    public function __invoke(PixelforgeClient $client): Response
    {
        return Inertia::render('admin/Report', [
            'daily' => fn () => $this->daily(),
            'perUser' => fn () => $this->perUser(),
            'totals' => fn () => $this->totals(),
            // Only fetched when the admin clicks "Reconcile" (partial reload).
            'reconciliation' => Inertia::optional(fn () => $this->reconcile($client)),
        ]);
    }

    /** Billable rows, bucketed by UTC completion date — the same rule /v1/usage uses. */
    private function billable()
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded->value)
            ->whereNotNull('completed_at');
    }

    private function daily(): array
    {
        return $this->billable()
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as generations, SUM(actual_user_cost_nanos) as revenue, SUM(actual_provider_cost_nanos) as provider')
            ->groupBy(DB::raw('DATE(completed_at)'))
            ->orderByDesc('day')
            ->limit(60)
            ->get()
            ->map(fn ($r) => self::row(['day' => (string) $r->day, 'generations' => (int) $r->generations], (int) $r->revenue, (int) $r->provider))
            ->all();
    }

    private function perUser(): array
    {
        return DB::table('users')
            ->leftJoin('generations', function ($join) {
                $join->on('generations.user_id', '=', 'users.id')
                    ->where('generations.status', GenerationStatus::Succeeded->value);
            })
            ->selectRaw('users.id, users.name, users.email, users.credit_balance_nanos, COUNT(generations.id) as generations,
                COALESCE(SUM(generations.actual_user_cost_nanos), 0) as revenue,
                COALESCE(SUM(generations.actual_provider_cost_nanos), 0) as provider')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.credit_balance_nanos')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => self::row([
                'user_id' => $r->id, 'name' => $r->name, 'email' => $r->email,
                'balance' => (string) $r->credit_balance_nanos, 'generations' => (int) $r->generations,
            ], (int) $r->revenue, (int) $r->provider))
            ->all();
    }

    private function totals(): array
    {
        $r = $this->billable()
            ->selectRaw('COUNT(*) as generations, COALESCE(SUM(actual_user_cost_nanos),0) as revenue, COALESCE(SUM(actual_provider_cost_nanos),0) as provider')
            ->first();

        $failed = Generation::where('status', GenerationStatus::Failed->value)->count();

        return self::row(['generations' => (int) $r->generations, 'failed' => $failed], (int) $r->revenue, (int) $r->provider);
    }

    private function reconcile(PixelforgeClient $client): array
    {
        try {
            $raw = $client->usage();
        } catch (PixelforgeApiException $e) {
            return ['ok' => false, 'error' => "{$e->errorCode}: {$e->getMessage()}", 'rows' => [], 'raw' => null];
        }

        $remote = self::parseUsage($raw);

        $local = $this->billable()
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as generations, SUM(actual_provider_cost_nanos) as provider')
            ->groupBy(DB::raw('DATE(completed_at)'))
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        $days = collect(array_keys($remote))->merge($local->keys())->unique()->sortDesc()->values();

        $rows = $days->map(function (string $day) use ($remote, $local) {
            $pf = $remote[$day] ?? null;
            $ours = (int) ($local[$day]->provider ?? 0);

            return [
                'day' => $day,
                'pixelforge_nanos' => $pf === null ? null : (string) $pf['nanos'],
                'pixelforge_generations' => $pf['generations'] ?? null,
                'local_nanos' => (string) $ours,
                'local_generations' => (int) ($local[$day]->generations ?? 0),
                'diff_nanos' => $pf === null ? null : (string) ($ours - $pf['nanos']),
                'match' => $pf !== null && $pf['nanos'] === $ours,
            ];
        })->all();

        return ['ok' => true, 'error' => null, 'rows' => $rows, 'raw' => $raw, 'fetched_at' => now()->toIso8601String()];
    }

    /**
     * Normalise GET /v1/usage into [YYYY-MM-DD => ['nanos' => int, 'generations' => ?int]].
     * Accepts a list of day rows under data/daily/days/usage, or the list itself.
     */
    public static function parseUsage(array $raw): array
    {
        $list = $raw['data'] ?? $raw['daily'] ?? $raw['days'] ?? $raw['usage'] ?? (array_is_list($raw) ? $raw : []);
        $out = [];

        foreach ((array) $list as $row) {
            if (! is_array($row)) {
                continue;
            }

            $day = $row['date'] ?? $row['day'] ?? null;
            $cost = $row['cost_usd'] ?? $row['total_cost_usd'] ?? $row['cost'] ?? null;

            if (! $day || $cost === null) {
                continue;
            }

            try {
                $out[substr((string) $day, 0, 10)] = [
                    'nanos' => Money::usdToNanos(is_array($cost) ? ($cost['usd'] ?? 0) : $cost),
                    'generations' => isset($row['generations']) ? (int) $row['generations'] : (isset($row['count']) ? (int) $row['count'] : null),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }

    private static function row(array $base, int $revenue, int $provider): array
    {
        return $base + [
            'revenue' => (string) $revenue,
            'provider' => (string) $provider,
            'profit' => (string) ($revenue - $provider),
        ];
    }
}
