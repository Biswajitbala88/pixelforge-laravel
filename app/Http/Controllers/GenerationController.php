<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Services\GenerationService;
use App\Services\Pixelforge\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request, PricingService $pricing): Response
    {
        return Inertia::render('Dashboard', [
            'pricing' => fn () => $pricing->current()->toFrontend(),
            'balance_nanos' => fn () => (string) $request->user()->fresh()->credit_balance_nanos,
            'generations' => fn () => GenerationResource::collection(
                $request->user()->generations()->latest()->limit(50)->get()
            )->resolve(),
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation submitted. The estimate is on hold until it finishes.']);

        return back();
    }
}
