export function ProgressBar({
  voter,
  voted,
  total,
  fullyDecided,
}: {
  voter: string;
  voted: number;
  total: number;
  fullyDecided: number;
}) {
  const pct = total > 0 ? Math.min(100, Math.round((voted / total) * 100)) : 0;
  return (
    <div className="w-full">
      <div className="mb-1 flex items-center justify-between text-xs font-medium text-neutral-300">
        <span className="truncate">
          {voter}: {voted} / {total}
        </span>
        <span className="shrink-0 text-neutral-400">decided: {fullyDecided}</span>
      </div>
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-neutral-800">
        <div
          className="h-full rounded-full bg-keep transition-[width] duration-300"
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}
