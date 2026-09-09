import { useEcho } from '@laravel/echo-vue';

export const useAutomationEcho = <T = unknown>(
    automationId: string,
    event: string | string[],
    callback: (payload: T) => void,
) => {
    if (import.meta.env.VITE_REALTIME_ENABLED !== 'true') {
        return;
    }

    return useEcho<T>(`automation.${automationId}`, event, callback);
};
