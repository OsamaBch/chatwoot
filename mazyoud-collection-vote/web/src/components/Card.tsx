import { formatPrice } from '../api';
import type { ProductCard, Vote } from '../types';

const OVERLAY: Record<Vote, { label: string; cls: string; rotate: string }> = {
  keep: { label: 'KEEP', cls: 'text-keep border-keep', rotate: '-rotate-12' },
  skip: { label: 'SKIP', cls: 'text-skip border-skip', rotate: 'rotate-12' },
  super: { label: '⭐ SUPER', cls: 'text-super border-super', rotate: '-rotate-6' },
};

export function Card({
  product,
  overlay,
  showDetails,
  loadImage = true,
}: {
  product: ProductCard;
  overlay: Vote | null;
  showDetails: boolean;
  loadImage?: boolean;
}) {
  return (
    <div className="relative h-full w-full overflow-hidden rounded-3xl bg-neutral-900 shadow-card ring-1 ring-white/10">
      {/* Image. `object-contain` shows the WHOLE product (square images aren't
          cropped); a blurred, scaled copy fills the letterbox behind it so the
          gaps aren't flat black. `loadImage` gates the network request so big
          decks only fetch the top + nearby cards. */}
      {product.image && loadImage !== false ? (
        <>
          <img
            src={product.image}
            aria-hidden="true"
            draggable={false}
            className="pointer-events-none absolute inset-0 h-full w-full scale-110 select-none object-cover opacity-40 blur-2xl"
          />
          <img
            src={product.image}
            alt=""
            draggable={false}
            className="pointer-events-none relative h-full w-full select-none object-contain"
          />
        </>
      ) : (
        <div className="flex h-full w-full items-center justify-center bg-neutral-800 text-neutral-600">
          {product.image ? '' : 'no image'}
        </div>
      )}

      {/* Bottom gradient for price legibility */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-black/80 to-transparent" />

      {/* Price pill — the only required metadata */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between p-4">
        <span className="rounded-full bg-black/70 px-4 py-2 text-2xl font-extrabold tracking-tight text-white backdrop-blur">
          {formatPrice(product.price)}
        </span>
        {showDetails && (
          <div className="max-w-[55%] text-right text-xs text-white/80">
            {product.title && <div className="truncate font-semibold">{product.title}</div>}
            {product.category && <div className="truncate">{product.category}</div>}
          </div>
        )}
      </div>

      {/* Drag-direction overlay */}
      {overlay && (
        <div className="pointer-events-none absolute inset-0 flex items-start justify-center pt-12">
          <span
            className={`rounded-2xl border-4 px-6 py-2 text-4xl font-black uppercase tracking-widest ${OVERLAY[overlay].cls} ${OVERLAY[overlay].rotate} bg-black/30 backdrop-blur-sm`}
          >
            {OVERLAY[overlay].label}
          </span>
        </div>
      )}
    </div>
  );
}
