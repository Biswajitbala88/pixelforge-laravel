/**
 * Mirror of App\Services\Pixelforge\Estimator. The server recomputes the estimate
 * on submit and that number is what gets held — this is for live display only.
 */
export type PricingProps = {
    source: 'live' | 'cached' | 'fallback';
    rates_nanos_per_million: Record<'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens', string>;
    output_tokens: Record<string, Record<string, number>>;
    reasoning_max: Record<string, number>;
    image_tokens_per_reference: number;
    markup_percent: number;
    sizes: string[];
    qualities: string[];
};

export type EstimateBreakdown = {
    tokens: { input_text_tokens: number; input_image_tokens: number; output_tokens: number; reasoning_tokens: number };
    providerNanos: bigint;
    userNanos: bigint;
};

const ceilDiv = (n: bigint, d: bigint): bigint => (n + d - 1n) / d;

/** ceil(ceil(chars / 4) * 1.15), counted in Unicode code points like mb_strlen. */
export function textTokens(prompt: string): number {
    const chars = BigInt([...prompt].length);
    return Number(ceilDiv(ceilDiv(chars, 4n) * 115n, 100n));
}

export function estimate(pricing: PricingProps, prompt: string, referenceCount: number, size: string, quality: string): EstimateBreakdown | null {
    const output = pricing.output_tokens[quality]?.[size];
    if (output === undefined) return null;

    const tokens = {
        input_text_tokens: textTokens(prompt.trim()), // the server trims input
        input_image_tokens: referenceCount * pricing.image_tokens_per_reference,
        output_tokens: output,
        reasoning_tokens: pricing.reasoning_max[quality] ?? 0,
    };

    const r = pricing.rates_nanos_per_million;
    const scaled =
        BigInt(tokens.input_text_tokens) * BigInt(r.input_text_tokens) +
        BigInt(tokens.input_image_tokens) * BigInt(r.input_image_tokens) +
        BigInt(tokens.output_tokens) * BigInt(r.output_tokens) +
        BigInt(tokens.reasoning_tokens) * BigInt(r.reasoning_tokens);

    const providerNanos = ceilDiv(scaled, 1_000_000n);
    const userNanos = ceilDiv(providerNanos * BigInt(pricing.markup_percent), 100n);

    return { tokens, providerNanos, userNanos };
}
