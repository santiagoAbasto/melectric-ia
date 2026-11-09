export interface DiffLine { type: 'same' | 'add' | 'del'; text: string }

/** Diff por líneas con LCS. */
export function lineDiff(a: string, b: string): DiffLine[] {
    const x = a.split(/\r?\n/);
    const y = b.split(/\r?\n/);
    const n = x.length;
    const m = y.length;
    // ponytail: tabla LCS O(n·m); alcanza para prompts de cientos de líneas. Myers si algún día hay miles.
    const L = Array.from({ length: n + 1 }, () => new Uint32Array(m + 1));
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            L[i][j] = x[i] === y[j] ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
        }
    }
    const out: DiffLine[] = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (x[i] === y[j]) {
            out.push({ type: 'same', text: x[i] });
            i++;
            j++;
        } else if (L[i + 1][j] >= L[i][j + 1]) {
            out.push({ type: 'del', text: x[i++] });
        } else {
            out.push({ type: 'add', text: y[j++] });
        }
    }
    while (i < n) out.push({ type: 'del', text: x[i++] });
    while (j < m) out.push({ type: 'add', text: y[j++] });
    return out;
}
