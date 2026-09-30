/**
 * "3 seconds ago" / "2 hours ago" — small and deliberately coarse
 * (seconds/minutes/hours/days only), enough for a worker-presence
 * badge. Not a general i18n date library.
 */
export function relativeTimeFromNow(iso: string): string {
    const seconds = Math.max(
        0,
        Math.floor((Date.now() - new Date(iso).getTime()) / 1000),
    );

    if (seconds < 5) {
        return 'just now';
    }
    if (seconds < 60) {
        return `${seconds} seconds ago`;
    }

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    }

    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    const days = Math.floor(hours / 24);
    return `${days} day${days === 1 ? '' : 's'} ago`;
}
