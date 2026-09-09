import { useEcho } from '@laravel/echo-vue';

export const useWebhookEcho = <T = unknown>(
    webhookId: string,
    event: string | string[],
    callback: (payload: T) => void,
) => {
    if (import.meta.env.VITE_REALTIME_ENABLED !== 'true') {
        return;
    }

    return useEcho<T>(`webhook.${webhookId}.logs`, event, callback);
};
