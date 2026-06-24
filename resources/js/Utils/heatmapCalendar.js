const MONTH_LABELS = [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "May",
    "Jun",
    "Jul",
    "Aug",
    "Sep",
    "Oct",
    "Nov",
    "Dec",
];

const MS_PER_DAY = 24 * 60 * 60 * 1000;

function isoFromUTCDate(date) {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, "0");
    const day = String(date.getUTCDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

export function buildYearGrid(year) {
    const yearStart = new Date(Date.UTC(year, 0, 1));
    const yearEnd = new Date(Date.UTC(year, 11, 31));

    // Pad back to the Sunday on/before Jan 1, forward to the Saturday on/after Dec 31.
    const gridStart = new Date(
        yearStart.getTime() - yearStart.getUTCDay() * MS_PER_DAY
    );
    const gridEnd = new Date(
        yearEnd.getTime() + (6 - yearEnd.getUTCDay()) * MS_PER_DAY
    );

    const weeks = [];
    let currentWeek = [];

    for (
        let cursor = gridStart.getTime();
        cursor <= gridEnd.getTime();
        cursor += MS_PER_DAY
    ) {
        const date = new Date(cursor);
        currentWeek.push({
            iso: isoFromUTCDate(date),
            inYear: date.getUTCFullYear() === year,
        });

        if (currentWeek.length === 7) {
            weeks.push(currentWeek);
            currentWeek = [];
        }
    }

    return { weeks };
}

export function monthLabelColumns(weeks) {
    const labels = [];
    let lastKeptColIndex = -Infinity;
    const seenMonths = new Set();

    weeks.forEach((week, colIndex) => {
        for (const cell of week) {
            if (!cell.inYear) {
                continue;
            }

            const [, monthStr, dayStr] = cell.iso.split("-");
            if (dayStr !== "01") {
                continue;
            }

            const monthIndex = Number(monthStr) - 1;
            if (seenMonths.has(monthIndex)) {
                continue;
            }
            seenMonths.add(monthIndex);

            if (colIndex - lastKeptColIndex < 3 && labels.length > 0) {
                continue;
            }

            labels.push({ label: MONTH_LABELS[monthIndex], colIndex });
            lastKeptColIndex = colIndex;
        }
    });

    return labels;
}

export function computeCellSize(containerWidth, weekCount, opts) {
    const { gap, min, max } = opts;

    if (!containerWidth || containerWidth <= 0 || weekCount <= 0) {
        return min;
    }

    // Total width = (size + gap) * weekCount - gap; solve for size.
    const raw = Math.floor((containerWidth + gap) / weekCount) - gap;

    return Math.max(min, Math.min(max, raw));
}
