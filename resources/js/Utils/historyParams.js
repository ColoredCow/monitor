// Builds the full history param set for an Inertia partial visit by merging
// the current param set with a control's change. Keys whose override value is
// null/undefined are removed entirely so they fall back to the server default.
export function buildHistoryParams(current, overrides) {
    const merged = { ...current, ...overrides };

    Object.keys(merged).forEach((key) => {
        if (merged[key] === null || merged[key] === undefined) {
            delete merged[key];
        }
    });

    return merged;
}
