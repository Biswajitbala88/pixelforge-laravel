/**
 * Money arrives from the server as nanodollar strings (1 USD = 1e9 nanos) and is
 * handled with BigInt, so the UI never does float arithmetic on money.
 */
export const NANOS_PER_USD = 1_000_000_000n;

export function toNanos(value: string | number | bigint | null | undefined): bigint {
    if (value === null || value === undefined || value === '') return 0n;
    return BigInt(value);
}

/** Exact 9-decimal string, e.g. 46125000n -> "0.046125000". */
export function exactUsd(nanos: bigint): string {
    const neg = nanos < 0n;
    const abs = neg ? -nanos : nanos;
    const whole = abs / NANOS_PER_USD;
    const frac = (abs % NANOS_PER_USD).toString().padStart(9, '0');
    return `${neg ? '-' : ''}${whole}.${frac}`;
}

/**
 * Display format: 4 decimals for amounts >= 1 cent ($0.0461), 6 for smaller ones
 * ($0.000213), 2 for whole-dollar-ish balances. Rounds half-up on the nanodollar
 * string — display only; stored values stay exact.
 */
export function formatUsd(nanos: bigint, opts: { decimals?: number } = {}): string {
    const abs = nanos < 0n ? -nanos : nanos;
    const decimals = opts.decimals ?? (abs >= 10_000_000n ? 4 : 6);
    const scale = 10n ** BigInt(9 - decimals);
    const rounded = (abs + scale / 2n) / scale; // half-up at the chosen precision
    const unit = 10n ** BigInt(decimals);
    const whole = rounded / unit;
    const frac = (rounded % unit).toString().padStart(decimals, '0');
    const wholeStr = whole.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${nanos < 0n ? '-' : ''}$${wholeStr}${decimals > 0 ? '.' + frac : ''}`;
}
