import { useState } from 'react';
import { ApiError, api } from '../api';

export function AdminLogin({
  onLoggedIn,
  onBack,
}: {
  onLoggedIn: () => void;
  onBack: () => void;
}) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!password || busy) return;
    setBusy(true);
    setError('');
    try {
      await api.adminLogin(password);
      onLoggedIn();
    } catch (err) {
      if (err instanceof ApiError && err.code === 'admin_not_configured') {
        setError('Admin is not configured. Set ADMIN_PASSWORD in the server .env.');
      } else if (err instanceof ApiError && err.code === 'invalid_credentials') {
        setError('Wrong password.');
      } else {
        setError('Login failed.');
      }
      setPassword('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="mx-auto flex h-full max-w-sm flex-col justify-center gap-5 px-6">
      <div className="text-center">
        <h1 className="text-2xl font-extrabold tracking-tight">Admin</h1>
        <p className="mt-1 text-sm text-neutral-400">Upload the sheet, manage voters, export results</p>
      </div>
      <input
        type="password"
        autoFocus
        value={password}
        onChange={(e) => {
          setPassword(e.target.value);
          setError('');
        }}
        placeholder="Admin password"
        className="w-full rounded-2xl bg-neutral-900 px-4 py-4 text-lg ring-1 ring-white/10 focus:outline-none focus:ring-2 focus:ring-keep"
      />
      {error && <p className="text-center text-sm text-skip">{error}</p>}
      <button
        type="submit"
        disabled={!password || busy}
        className="w-full rounded-2xl bg-keep px-4 py-4 text-lg font-bold text-white shadow-card transition active:scale-[0.99] disabled:opacity-40"
      >
        {busy ? 'Signing in…' : 'Enter'}
      </button>
      <button type="button" onClick={onBack} className="text-center text-xs text-neutral-500 underline">
        ← back to voting
      </button>
    </form>
  );
}
