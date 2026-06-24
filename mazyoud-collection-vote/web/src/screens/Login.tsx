import { useEffect, useState } from 'react';
import { ApiError, api } from '../api';

export function Login({
  onLoggedIn,
  onAdmin,
}: {
  onLoggedIn: (voter: string, votedKeys: string[]) => void;
  onAdmin: () => void;
}) {
  const [voters, setVoters] = useState<string[]>([]);
  const [name, setName] = useState('');
  const [pin, setPin] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api
      .voters()
      .then((r) => {
        setVoters(r.voters);
        if (r.voters.length === 1) setName(r.voters[0]);
      })
      .catch(() => setError('Could not load voters. Check the Voters tab.'));
  }, []);

  const press = (d: string) => {
    setError('');
    setPin((p) => (p.length >= 8 ? p : p + d));
  };
  const backspace = () => setPin((p) => p.slice(0, -1));
  const clear = () => setPin('');

  const submit = async () => {
    if (!name || !pin || busy) return;
    setBusy(true);
    setError('');
    try {
      const { voter, votedKeys } = await api.login(name, pin);
      onLoggedIn(voter, votedKeys);
    } catch (e) {
      if (e instanceof ApiError && e.code === 'invalid_credentials') {
        setError('Wrong PIN. Try again.');
      } else if (e instanceof ApiError && e.code === 'too_many_attempts') {
        setError('Too many attempts. Wait a minute.');
      } else {
        setError('Login failed. Try again.');
      }
      setPin('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex h-full max-w-sm flex-col justify-center gap-6 px-6">
      <div className="text-center">
        <h1 className="text-2xl font-extrabold tracking-tight">Mazyoud Collection Vote</h1>
        <p className="mt-1 text-sm text-neutral-400">Pick your name and enter your PIN</p>
      </div>

      <select
        value={name}
        onChange={(e) => {
          setName(e.target.value);
          setError('');
        }}
        className="w-full rounded-2xl bg-neutral-900 px-4 py-4 text-lg ring-1 ring-white/10 focus:outline-none focus:ring-2 focus:ring-keep"
      >
        <option value="" disabled>
          Select your name…
        </option>
        {voters.map((v) => (
          <option key={v} value={v}>
            {v}
          </option>
        ))}
      </select>

      {/* PIN dots */}
      <div className="flex items-center justify-center gap-3" aria-label="PIN entry">
        {Array.from({ length: Math.max(4, pin.length) }).map((_, i) => (
          <span
            key={i}
            className={`h-3.5 w-3.5 rounded-full ${
              i < pin.length ? 'bg-keep' : 'bg-neutral-700'
            }`}
          />
        ))}
      </div>

      {error && <p className="text-center text-sm text-skip">{error}</p>}

      {/* PIN pad */}
      <div className="grid grid-cols-3 gap-3">
        {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((d) => (
          <PadButton key={d} onClick={() => press(d)}>
            {d}
          </PadButton>
        ))}
        <PadButton onClick={clear} variant="ghost">
          C
        </PadButton>
        <PadButton onClick={() => press('0')}>0</PadButton>
        <PadButton onClick={backspace} variant="ghost">
          ⌫
        </PadButton>
      </div>

      <button
        onClick={submit}
        disabled={!name || !pin || busy}
        className="w-full rounded-2xl bg-keep px-4 py-4 text-lg font-bold text-white shadow-card transition active:scale-[0.99] disabled:opacity-40"
      >
        {busy ? 'Signing in…' : 'Enter'}
      </button>

      <button onClick={onAdmin} className="text-center text-xs text-neutral-600 underline">
        admin
      </button>
    </div>
  );
}

function PadButton({
  children,
  onClick,
  variant = 'solid',
}: {
  children: React.ReactNode;
  onClick: () => void;
  variant?: 'solid' | 'ghost';
}) {
  return (
    <button
      onClick={onClick}
      className={`flex h-16 items-center justify-center rounded-2xl text-2xl font-semibold transition active:scale-95 ${
        variant === 'solid'
          ? 'bg-neutral-900 ring-1 ring-white/10'
          : 'bg-transparent text-neutral-400'
      }`}
    >
      {children}
    </button>
  );
}
