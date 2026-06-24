import { createRef, useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
  // currentIndex points at the top card in `stack` (see below). It starts at
  // the last index and counts DOWN to -1 (done) — the proven react-tinder-card
  // pattern where the visually-top card is the last element rendered.
  const [currentIndex, setCurrentIndex] = useState(-1);
  const currentIndexRef = useRef(-1);
  const [myVotes, setMyVotes] = useState<Map<string, Vote>>(new Map());
  const [overlay, setOverlay] = useState<{ key: string; vote: Vote } | null>(null);
  const [fullyDecided, setFullyDecided] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [undoBusy, setUndoBusy] = useState(false);

  // history of swipes for undo: which stack index each swipe was at.
  const history = useRef<{ index: number; product_key: string }[]>([]);
  const doneFired = useRef(false);

  // `stack` = deck reversed so the FIRST product to vote is the last element
  // (= painted on top). All cards stay mounted, which is what makes swipe
  // advance + restoreCard (undo) reliable.
  const stack = useMemo(() => [...deck].reverse(), [deck]);
  const childRefs = useMemo(
    () => Array.from({ length: stack.length }, () => createRef<CardApi>()),
    [stack.length],
  );

  const setIndex = useCallback((v: number) => {
    currentIndexRef.current = v;
    setCurrentIndex(v);
  }, []);

  // --- load products ------------------------------------------------------
  useEffect(() => {
    let active = true;
    setLoading(true);
    doneFired.current = false;
    history.current = [];
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
        setIndex(toSwipe.length - 1); // top of the (reversed) stack
      })
      .catch(() => setError('Could not load products.'))
      .finally(() => active && setLoading(false));
    return () => {
      active = false;
    };
  }, [category, allowRevote, setIndex]);

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
    if (loading || doneFired.current) return;
    if (deck.length === 0 || currentIndex < 0) {
      doneFired.current = true;
      onDone(tally);
    }
  }, [loading, currentIndex, deck.length, tally, onDone]);

  // --- vote / undo --------------------------------------------------------
  const swiped = useCallback(
    (dir: Direction, product: ProductCard, index: number) => {
      const vote = dirToVote(dir);
      if (!vote) return;
      setOverlay(null);
      history.current.push({ index, product_key: product.product_key });
      setMyVotes((m) => new Map(m).set(product.product_key, vote));
      setIndex(index - 1);
      api.vote(product.product_key, product.row_anchor, vote).catch((e) => {
        if (e instanceof ApiError && e.code === 'already_voted') return;
        setError('Vote failed to save — check connection.');
      });
    },
    [setIndex],
  );

  const swipe = useCallback(
    async (dir: Direction) => {
      const i = currentIndexRef.current;
      if (i < 0) return;
      const ref = childRefs[i]?.current;
      if (ref) {
        try {
          await ref.swipe(dir);
        } catch {
          /* ignore */
        }
      }
    },
    [childRefs],
  );

  const goBack = useCallback(async () => {
    if (undoBusy) return;
    const last = history.current.pop();
    if (!last) return;
    setUndoBusy(true);
    setOverlay(null);
    setMyVotes((m) => {
      const next = new Map(m);
      next.delete(last.product_key);
      return next;
    });
    setIndex(last.index);
    const ref = childRefs[last.index]?.current;
    if (ref) {
      try {
        await ref.restoreCard();
      } catch {
        /* ignore */
      }
    }
    try {
      await api.undo({ product_key: last.product_key });
    } catch {
      /* optimistic state already reflects the undo */
    } finally {
      setUndoBusy(false);
    }
  }, [undoBusy, childRefs, setIndex]);

  // --- keyboard shortcuts -------------------------------------------------
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        void swipe('left');
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        void swipe('right');
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        void swipe('up');
      } else if (e.key === 'Backspace' || e.key.toLowerCase() === 'z') {
        e.preventDefault();
        void goBack();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [swipe, goBack]);

  // --- render -------------------------------------------------------------
  if (loading) return <Centered>Loading products…</Centered>;
  if (error && deck.length === 0) return <Centered>{error}</Centered>;

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
        {stack.map((product, index) => {
          const depth = currentIndex - index; // 0 = top, 1/2 = beneath, <0 = swiped
          const isTop = depth === 0;
          const hidden = depth > 2 || depth < 0; // keep mounted but out of the way
          return (
            <div
              key={product.product_key}
              className="swipe-card"
              style={{
                transform:
                  depth >= 0 ? `translateY(${depth * 7}px) scale(${1 - depth * 0.03})` : undefined,
                opacity: hidden ? 0 : 1,
                pointerEvents: isTop ? 'auto' : 'none',
                zIndex: depth < 0 ? 100 : 50 - depth,
              }}
            >
              <TinderCard
                ref={childRefs[index]}
                className="h-full w-full"
                preventSwipe={['down']}
                swipeRequirementType="position"
                swipeThreshold={90}
                onSwipe={(dir) => swiped(dir as Direction, product, index)}
                onSwipeRequirementFulfilled={(dir) => {
                  const v = dirToVote(dir as Direction);
                  if (v && isTop) setOverlay({ key: product.product_key, vote: v });
                }}
                onSwipeRequirementUnfulfilled={() => {
                  setOverlay((o) => (o?.key === product.product_key ? null : o));
                }}
              >
                <Card
                  product={product}
                  overlay={overlay?.key === product.product_key ? overlay.vote : null}
                  showDetails={showDetails}
                  loadImage={depth >= -1 && depth <= 4}
                />
              </TinderCard>
            </div>
          );
        })}
      </div>

      {/* Controls */}
      <div className="flex items-center justify-center gap-4 pb-2">
        <CtrlButton label="Skip" color="skip" onClick={() => void swipe('left')}>
          ✕
        </CtrlButton>
        <CtrlButton label="Undo" color="neutral" small disabled={undoBusy} onClick={() => void goBack()}>
          ↺
        </CtrlButton>
        <CtrlButton label="Super" color="super" small onClick={() => void swipe('up')}>
          ★
        </CtrlButton>
        <CtrlButton label="Keep" color="keep" onClick={() => void swipe('right')}>
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
