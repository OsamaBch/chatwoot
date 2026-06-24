import { useEffect, useState } from 'react';
import { api } from '../api';

export function CategoryPicker({
  voter,
  onPick,
  onLogout,
}: {
  voter: string;
  onPick: (category: string) => void;
  onLogout: () => void;
}) {
  const [categories, setCategories] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .categories()
      .then((r) => setCategories(r.categories))
      .catch(() => setCategories([]))
      .finally(() => setLoading(false));
  }, []);

  // No categories in the sheet → skip the picker entirely.
  useEffect(() => {
    if (!loading && categories.length === 0) onPick('All');
  }, [loading, categories, onPick]);

  return (
    <div className="mx-auto flex h-full max-w-sm flex-col gap-4 px-6 py-8">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-extrabold">Hi {voter} 👋</h1>
          <p className="text-sm text-neutral-400">What do you want to vote on?</p>
        </div>
        <button onClick={onLogout} className="text-xs text-neutral-500 underline">
          logout
        </button>
      </div>

      <div className="flex-1 overflow-y-auto">
        <button
          onClick={() => onPick('All')}
          className="mb-3 w-full rounded-2xl bg-keep px-4 py-4 text-left text-lg font-bold shadow-card active:scale-[0.99]"
        >
          All products
        </button>
        <div className="grid grid-cols-2 gap-3">
          {categories.map((c) => (
            <button
              key={c}
              onClick={() => onPick(c)}
              className="rounded-2xl bg-neutral-900 px-4 py-4 text-left text-sm font-semibold ring-1 ring-white/10 active:scale-95"
            >
              {c}
            </button>
          ))}
        </div>
        {loading && <p className="mt-6 text-center text-sm text-neutral-500">Loading…</p>}
      </div>
    </div>
  );
}
