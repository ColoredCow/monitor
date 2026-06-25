// Build the strip's slot array from a newest-first `checks` list.
// Returns an array of length `capacity` (left -> right): leading nulls are gray
// placeholders when there are fewer checks than slots; the most-recent checks
// trail with the NEWEST at the last (right-most) index.
export function stripSlots(checks, capacity) {
    const cap = Math.max(0, capacity);
    const recent = checks.slice(0, cap); // most-recent `cap`, still newest-first
    const ordered = recent.slice().reverse(); // oldest -> newest (newest last)
    const padCount = Math.max(0, cap - ordered.length);

    return [...Array(padCount).fill(null), ...ordered];
}
