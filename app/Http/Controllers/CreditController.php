<?php

namespace App\Http\Controllers;

use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CreditController extends Controller
{
    /** Fake checkout: whitelisted amounts only. */
    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate(['amount_usd' => ['required', 'integer', 'in:5,10']]);

        $ledger->topUp($request->user(), $data['amount_usd'] * Money::NANOS_PER_USD, "Purchased \${$data['amount_usd']}.00 credits");

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added \${$data['amount_usd']}.00 in credits."]);

        return back();
    }
}
