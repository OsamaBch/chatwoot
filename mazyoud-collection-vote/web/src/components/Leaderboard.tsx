export function Leaderboard({
  rows,
}: {
  rows: { voter: string; voted: number }[];
}) {
  if (rows.length === 0) return null;
  return (
    <div className="w-full rounded-2xl bg-neutral-900 p-4 ring-1 ring-white/10">
      <h3 className="mb-2 text-sm font-semibold text-neutral-300">Leaderboard</h3>
      <ul className="space-y-1">
        {rows.map((r, i) => (
          <li key={r.voter} className="flex items-center justify-between text-sm">
            <span className="flex items-center gap-2">
              <span className="w-5 text-right text-neutral-500">{i + 1}.</span>
              <span className="font-medium text-neutral-100">{r.voter}</span>
            </span>
            <span className="tabular-nums text-neutral-300">{r.voted}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
