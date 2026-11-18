export type GenerationStatus = 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: string;
    pixelforge_id: string | null;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_user_cost_nanos: string;
    actual_user_cost_nanos: string | null;
    usage: Record<string, number> | null;
    image_url: string | null;
    image_expired: boolean;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    error_detail: string | null;
    retrying: boolean;
    created_at: string;
    completed_at: string | null;
};

export const ACTIVE_STATUSES: GenerationStatus[] = ['submitting', 'queued', 'processing'];
