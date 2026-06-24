import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import TinderCard from 'react-tinder-card';
import { ApiError, api } from '../api';
import { Card } from '../components/Card';
import { ProgressBar } from '../components/ProgressBar';
import type { ProductCard, Vote } from '../types';

type Direction = 'left' | 'right' | 'up' | 'down';

interface CardApi {
  swipe: (dir?: Direction) => Promise<void>;
  restoreCard: () => Promise<void>;
}

export interface VoteTally {
  keep: number;
  skip: number;
  super: number;
  total: number;
  category: string;
}

const WINDOW = 3; // number of stacked cards mounted at once (perf for 377+ sheets)

function dirToVote(dir: Direction): Vote | null {
  if (dir === 'right') return 'keep';
  if (dir === 'left') return 'skip';
  if (dir === 'up') return 'super';
  return null; // down is prevented
}

export function Deck({
  voter,
  category,
  allowRevote,
  showDetails,
  onDone,
  onLogout,
}: {
  voter: string;
  category: string;
  allowRevote: boolean;
  showDetails: boolean;
  onDone: (tally: VoteTally) => void;
  onLogout: () => void;
}) {
  const [all, setAll] = useState<ProductCard[]>([]);
  const [deck, setDeck] = useState<ProductCard[]>([]);
  const [pointer, setPointer] = useState(0);
  const [myVotes, setMyVotes] = useState<Map<string, Vote>>(new Map());
  const [overlay, setOverlay] = useState<{ key: string; vote: Vote } | null>(null);
  const [fullyDecided, setFullyDecided] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [undoBusy, setUndoBusy] = useState(false);

  const cardRefs = useRef<Record<string, CardApi | null>>({});
  // history of swipes for robust undo: where each card was the top card.
  const history = useRef<{ product_key: string; vote: Vote; pointerAt: number }[]>([]);

  // --- load products ------------------------------------------------------
  useEffect(() => {
    let active = true;
    setLoading(true);
    api
      .products(category)
      .then((r) => {
        if (!active) return;
        setAll(r.products);
        const voted = new Map<string, Vote>();
        for (const p of r.products) if (p.myVote) voted.set(p.product_key, p.myVote);
        setMyVotes(voted);
        const toSwipe = allowRevote ? r.products : r.products.filter((p) => !p.myVote);
        setDeck(toSwipe);
        setPointer(0);
      })
      .catch(() => setError('Could not load products.'))
      .finally(() => active && setLoading(false));
    return () => {
      active = false;
    };
  }, [category, allowRevote]);

  // --- live "fully decided" overall counter ------------------------------
  useEffect(() => {
    let active = true;
    const tick = () =>
      api
        .progress()
        .then((p) => active && setFullyDecided(p.fullyDecided))
        .catch(() => {});
    tick();
    const id = setInterval(tick, 20000);
    return () => {
      active = false;
      clearInterval(id);
    };
  }, []);

  const total = all.length;
  const votedCount = myVotes.size;

  const tally = useMemo<VoteTally>(() => {
    let keep = 0;
    let skip = 0;
    let sup = 0;
    for (const v of myVotes.values()) {
      if (v === 'keep') keep++;
      else if (v === 'skip') skip++;
      else if (v === 'super') sup++;
    }
    return { keep, skip, super: sup, total, category };
  }, [myVotes, total, category]);

  // --- done detection -----------------------------------------------------
  useEffect(() => {
    if (!loading && deck.length > 0 && pointer >= deck.length) {
      onDone(tally);
    }
    if (!loading && deck.length === 0) {
      onDone(tally);
    }
  }, [loading, deck.length, pointer, onDone, tally]);

  // --- vote / undo --------------------------------------------------------
  const handleSwipe = useCallback(
    (dir: Direction, product: ProductCard, atPointer: number) => {
      const vote = dirToVote(dir);
      if (!vote) return;
      setOverlay(null);
      history.current.push({ product_key: product.product_key, vote, pointerAt: atPointer });
      setMyVotes((m) => {
        const next = new Map(m);
        next.set(product.product_key, vote);
        return next;
      });
      api.vote(product.product_key, product.row_anchor, vote).catch((e) => {
        // already_voted (revote disabled) is benign — keep optimistic state.
        if (e instanceof ApiError && e.code === 'already_voted') return;
        setError('Vote failed to save — check connection.');
      });
    },
    [],
  );

  const handleLeftScreen = useCallback((product_key: string) => {
    setPointer((p) => p + 1);
    setOverlay((o) => (o?.key === product_key ? null : o));
    // free the ref for the card that's gone
    delete cardRefs.current[product_key];
  }, []);

  const triggerSwipe = useCallback(
    (dir: Direction) => {
      const top = deck[pointer];
      if (!top) return;
      const ref = cardRefs.current[top.product_key];
      if (ref?.swipe) {
        void ref.swipe(dir);
      } else {
        // Fallback when the ref isn't ready: record + advance manually.
        handleSwipe(dir, top, pointer);
        handleLeftScreen(top.product_key);
      }
    },
    [deck, pointer, handleSwipe, handleLeftScreen],
  );

  const handleUndo = useCallback(async () => {
    if (undoBusy) return;
    const last = history.current[history.current.length - 1];
    if (!last) return;
    setUndoBusy(true);
    history.current.pop();

    // Bring the card back: restoreCard if still mounted, else re-mount via pointer.
    setPointer(last.pointerAt);
    const ref = cardRefs.current[last.product_key];
    if (ref?.restoreCard) {
      try {
        await ref.restoreCard();
      } catch {
        /* ignore */
      }
    }
    setMyVotes((m) => {
      const next = new Map(m);
      next.delete(last.product_key);
      return next;
    });
    try {
      await api.undo({ product_key: last.product_key });
    } catch {
      /* the local optimistic state already reflects the undo */
    } finally {
      setUndoBusy(false);
    }
  }, [undoBusy]);

  // --- keyboard shortcuts -------------------------------------------------
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        triggerSwipe('left');
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        triggerSwipe('right');
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        triggerSwipe('up');
      } else if (e.key === 'Backspace' || e.key.toLowerCase() === 'z') {
        e.preventDefault();
        void handleUndo();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [triggerSwipe, handleUndo]);

  // --- render -------------------------------------------------------------
  if (loading) {
    return <Centered>Loading products…</Centered>;
  }
  if (error && deck.length === 0) {
    return <Centered>{error}</Centered>;
  }

  // Visible window: highest index first (bottom of stack), pointer last (top).
  const visible: { product: ProductCard; idx: number }[] = [];
  for (let i = Math.min(pointer + WINDOW - 1, deck.length - 1); i >= pointer; i--) {
    if (deck[i]) visible.push({ product: deck[i], idx: i });
  }

  return (
    <div className="mx-auto flex h-full max-w-md flex-col gap-3 px-4 py-3">
      {/* Top bar */}
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <ProgressBar voter={voter} voted={votedCount} total={total} fullyDecided={fullyDecided} />
        </div>
        <button onClick={onLogout} className="shrink-0 text-xs text-neutral-500 underline">
          exit
        </button>
      </div>

      {error && <p className="text-center text-xs text-skip">{error}</p>}

      {/* Deck area */}
      <div className="relative flex-1">
        {visible.map(({ product, idx }) => {
          const depth = idx - pointer; // 0 = top
          const isTop = depth === 0;
          return (
            <div
              key={product.product_key}
              className="swipe-card"
              style={{
                transform: `translateY(${depth * 10}px) scale(${1 - depth * 0.04})`,
                zIndex: WINDOW - depth,
                pointerEvents: isTop ? 'auto' : 'none',
                opacity: depth > 1 ? 0.0 : 1,
              }}
            >
              <TinderCard
                ref={(el) => {
                  cardRefs.current[product.product_key] = el as unknown as CardApi;
                }}
                className="h-full w-full"
                preventSwipe={['down']}
                swipeRequirementType="position"
                swipeThreshold={100}
                onSwipe={(dir) => handleSwipe(dir as Direction, product, idx)}
                onCardLeftScreen={() => handleLeftScreen(product.product_key)}
                onSwipeRequirementFulfilled={(dir) => {
                  const v = dirToVote(dir as Direction);
                  if (v && isTop) setOverlay({ key: product.product_key, vote: v });
                }}
                onSwipeRequirementUnfulfilled={() => {
                  if (isTop) setOverlay((o) => (o?.key === product.product_key ? null : o));
                }}
              >
                <Card
                  product={product}
                  overlay={overlay?.key === product.product_key ? overlay.vote : null}
                  showDetails={showDetails}
                />
              </TinderCard>
            </div>
          );
        })}
      </div>

      {/* Controls */}
      <div className="flex items-center justify-center gap-4 pb-2">
        <CtrlButton label="Skip" color="skip" onClick={() => triggerSwipe('left')}>
          ✕
        </CtrlButton>
        <CtrlButton label="Undo" color="neutral" small disabled={undoBusy} onClick={() => void handleUndo()}>
          ↺
        </CtrlButton>
        <CtrlButton label="Super" color="super" small onClick={() => triggerSwipe('up')}>
          ★
        </CtrlButton>
        <CtrlButton label="Keep" color="keep" onClick={() => triggerSwipe('right')}>
          ♥
        </CtrlButton>
      </div>
    </div>
  );
}

function CtrlButton({
  children,
  label,
  color,
  small,
  disabled,
  onClick,
}: {
  children: React.ReactNode;
  label: string;
  color: 'keep' | 'skip' | 'super' | 'neutral';
  small?: boolean;
  disabled?: boolean;
  onClick: () => void;
}) {
  const ring =
    color === 'keep'
      ? 'text-keep ring-keep/40'
      : color === 'skip'
        ? 'text-skip ring-skip/40'
        : color === 'super'
          ? 'text-super ring-super/40'
          : 'text-neutral-300 ring-white/15';
  const size = small ? 'h-14 w-14 text-xl' : 'h-[4.5rem] w-[4.5rem] text-3xl';
  return (
    <button
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className={`flex ${size} items-center justify-center rounded-full bg-neutral-900 ring-2 ${ring} shadow-card transition active:scale-90 disabled:opacity-30`}
    >
      {children}
    </button>
  );
}

function Centered({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex h-full items-center justify-center px-6 text-center text-neutral-400">
      {children}
    </div>
  );
}
