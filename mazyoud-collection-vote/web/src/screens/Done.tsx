import { useEffect, useState } from 'react';
import { api } from '../api';
import { Leaderboard } from '../components/Leaderboard';
import type { VoteTally } from './Deck';

export function Done({
  voter,
  tally,
  onResults,
  onChangeCategory,
  onLogout,
}: {
  voter: string;
  tally: VoteTally;
  onResults: () => void;
  onChangeCategory: () => void;
  onLogout: () => void;
}) {
  const [leaderboard, setLeaderboard] = useState<{ voter: string; voted: number }[]>([]);

  useEffect(() => {
    api
      .progress()
      .then((p) => setLeaderboard(p.leaderboard))
      .catch(() => {});
  }, []);

  return (
    <div className="mx-auto flex h-full max-w-sm flex-col gap-5 overflow-y-auto px-6 py-8">
      <div className="text-center">
        <div className="text-5xl">🎉</div>
        <h1 className="mt-2 text-2xl font-extrabold">All done, {voter}!</h1>
        <p className="text-sm text-neutral-400">
          {tally.category === 'All' ? 'All products' : tally.category} · {tally.total} items
        </p>
      </div>

      <div className="grid grid-cols-3 gap-3">
        <Stat label="Keep" value={tally.keep} color="text-keep" />
        <Stat label="Super" value={tally.super} color="text-super" />
        <Stat label="Skip" value={tally.skip} color="text-skip" />
      </div>

      <Leaderboard rows={leaderboard} />

      <div className="mt-2 flex flex-col gap-3">
        <button
          onClick={onResults}
          className="w-full rounded-2xl bg-keep px-4 py-4 text-lg font-bold shadow-card active:scale-[0.99]"
        >
          See ranked results →
        </button>
        <button
          onClick={onChangeCategory}
          className="w-full rounded-2xl bg-neutral-900 px-4 py-4 font-semibold ring-1 ring-white/10 active:scale-[0.99]"
        >
          Vote another category
        </button>
        <button onClick={onLogout} className="text-center text-xs text-neutral-500 underline">
          logout
        </button>
      </div>
    </div>
  );
}

function Stat({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div className="rounded-2xl bg-neutral-900 p-4 text-center ring-1 ring-white/10">
      <div className={`text-3xl font-extrabold tabular-nums ${color}`}>{value}</div>
      <div className="text-xs text-neutral-400">{label}</div>
    </div>
  );
}
