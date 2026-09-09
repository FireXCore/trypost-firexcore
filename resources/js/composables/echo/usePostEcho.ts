import { useEcho } from '@laravel/echo-vue';

export const usePostEcho = <T = unknown>(
    postId: string,
    event: string | string[],
    callback: (payload: T) => void,
) => {
    if (import.meta.env.VITE_REALTIME_ENABLED !== 'true') {
        return;
    }

    return useEcho<T>(`post.${postId}`, event, callback);
};
