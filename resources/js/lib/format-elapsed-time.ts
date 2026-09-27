/**
 * "M:SS" — minutes unpadded, seconds always two digits (e.g. 0:00,
 * 0:42, 1:00, 12:05). A plain duration, never a percentage or a
 * countdown.
 */
export function formatElapsedTime(totalSeconds: number): string {
    const safeSeconds = Math.max(0, Math.floor(totalSeconds));
    const minutes = Math.floor(safeSeconds / 60);
    const seconds = safeSeconds % 60;

    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}
