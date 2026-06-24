import { useCallback, useEffect, useState } from 'react';
import { api } from './api';
import { CategoryPicker } from './screens/CategoryPicker';
import { Deck, type VoteTally } from './screens/Deck';
import { Done } from './screens/Done';
import { Login } from './screens/Login';
import { Results } from './screens/Results';
import type { AppConfig } from './types';

type Screen = 'boot' | 'login' | 'category' | 'deck' | 'done' | 'results';

export default function App() {
  const [screen, setScreen] = useState<Screen>('boot');
  const [voter, setVoter] = useState('');
  const [category, setCategory] = useState('All');
  const [cfg, setCfg] = useState<AppConfig>({
    showDetails: false,
    allowRevote: false,
    appName: 'Mazyoud Collection Vote',
  });
  const [tally, setTally] = useState<VoteTally>({
    keep: 0,
    skip: 0,
    super: 0,
    total: 0,
    category: 'All',
  });

  // Boot: load config + resume any existing session.
  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const c = await api.config();
        if (active) setCfg(c);
      } catch {
        /* keep defaults */
      }
      try {
        const me = await api.me();
        if (active && me.voter) {
          setVoter(me.voter);
          setScreen('category');
          return;
        }
      } catch {
        /* not logged in */
      }
      if (active) setScreen('login');
    })();
    return () => {
      active = false;
    };
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.logout();
    } catch {
      /* ignore */
    }
    setVoter('');
    setCategory('All');
    setScreen('login');
  }, []);

  switch (screen) {
    case 'boot':
      return (
        <div className="flex h-full items-center justify-center text-neutral-500">Loading…</div>
      );

    case 'login':
      return (
        <Login
          onLoggedIn={(v) => {
            setVoter(v);
            setScreen('category');
          }}
        />
      );

    case 'category':
      return (
        <CategoryPicker
          voter={voter}
          onPick={(c) => {
            setCategory(c);
            setScreen('deck');
          }}
          onLogout={logout}
        />
      );

    case 'deck':
      return (
        <Deck
          voter={voter}
          category={category}
          allowRevote={cfg.allowRevote}
          showDetails={cfg.showDetails}
          onDone={(t) => {
            setTally(t);
            setScreen('done');
          }}
          onLogout={logout}
        />
      );

    case 'done':
      return (
        <Done
          voter={voter}
          tally={tally}
          onResults={() => setScreen('results')}
          onChangeCategory={() => setScreen('category')}
          onLogout={logout}
        />
      );

    case 'results':
      return <Results onBack={() => setScreen('done')} />;
  }
}
