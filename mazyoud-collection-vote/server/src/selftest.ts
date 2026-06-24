/**
 * Lightweight self-test for the correctness-critical pure logic:
 * anchor detection / image extraction / price reading (parse.ts) and
 * vote resolution + verdict rules (aggregate.ts).
 *
 * Run: npm test   (uses tsx, no test framework needed)
 */
import assert from 'node:assert';
import { COL } from './config';
import { extractImageUrl, parseProducts, readPrice } from './sheets/parse';
import {
  lastVoteIdFor,
  resolveProductVotes,
  resolveVoterVotes,
  tally,
} from './aggregate';
import type { VoteRow } from './sheets/types';

let passed = 0;
function check(name: string, fn: () => void) {
  fn();
  passed++;
  console.log(`  ✓ ${name}`);
}

// --- build a mock AID 1 grid (10-row blocks, blank in product cols) --------
function emptyRow(): unknown[] {
  return new Array(19).fill('');
}
function set(row: unknown[], idx: number, val: unknown): void {
  row[idx] = val;
}

/** Build formula+value grids with `count` products spaced 10 rows apart. */
function mockGrid(count: number) {
  const formula: unknown[][] = [];
  const value: unknown[][] = [];
  // header (row 0)
  formula.push(emptyRow());
  value.push(emptyRow());
  for (let p = 0; p < count; p++) {
    for (let r = 0; r < 10; r++) {
      const f = emptyRow();
      const v = emptyRow();
      if (r === 0) {
        // anchor row
        set(f, COL.IMAGE, `=IMAGE("https://cbu01.alicdn.com/img${p}.jpg")`);
        set(v, COL.PRICE, 3000 + p * 50);
        set(v, COL.CATEGORY, p % 2 === 0 ? 'Shoes' : 'Bags');
        set(v, COL.GENDER, 'Men');
        set(v, COL.TITLE, `Product ${p}`);
        // Every other product gets a product link; others fall back to row-N.
        if (p % 2 === 0) set(v, COL.PRODUCT_LINK, `https://1688.com/offer/${p}`);
      }
      formula.push(f);
      value.push(v);
    }
  }
  return { formula, value };
}

console.log('parse.ts');

check('extractImageUrl: double quotes', () => {
  assert.equal(
    extractImageUrl('=IMAGE("https://x.alicdn.com/a.jpg")'),
    'https://x.alicdn.com/a.jpg',
  );
});
check('extractImageUrl: single quotes', () => {
  assert.equal(extractImageUrl("=IMAGE('https://x/y.png')"), 'https://x/y.png');
});
check('extractImageUrl: extra mode/size args', () => {
  assert.equal(extractImageUrl('=IMAGE("https://x/z.jpg", 4, 80, 80)'), 'https://x/z.jpg');
});
check('extractImageUrl: bare url fallback', () => {
  assert.equal(extractImageUrl('https://x/bare.jpg'), 'https://x/bare.jpg');
});
check('extractImageUrl: empty -> null', () => {
  assert.equal(extractImageUrl(''), null);
  assert.equal(extractImageUrl(null), null);
});

check('readPrice: cached number', () => assert.equal(readPrice(3950, '3 950 DZD'), 3950));
check('readPrice: formatted fallback', () => assert.equal(readPrice(null, '3 950 DZD'), 3950));
check('readPrice: comma thousands fallback', () => assert.equal(readPrice('', '4,000 DZD'), 4000));
check('readPrice: empty -> null', () => assert.equal(readPrice(null, ''), null));

check('anchor detection: 14 products', () => {
  const { formula, value } = mockGrid(14);
  const products = parseProducts(formula, value, [], 1);
  assert.equal(products.length, 14);
  // first anchor is sheet row 2 (after header)
  assert.equal(products[0].row_anchor, 2);
  // blocks are 10 rows apart
  assert.equal(products[1].row_anchor, 12);
  assert.equal(products[0].image_url, 'https://cbu01.alicdn.com/img0.jpg');
  assert.equal(products[0].price, 3000);
  assert.equal(products[0].product_key, 'https://1688.com/offer/0');
  // odd products have no M -> fall back to row-<anchor>
  assert.equal(products[1].product_key, 'row-12');
});

check('anchor detection: scales to 377 (dynamic, no fixed count)', () => {
  const { formula, value } = mockGrid(377);
  const products = parseProducts(formula, value, [], 1);
  assert.equal(products.length, 377);
  assert.equal(products[376].row_anchor, 2 + 376 * 10);
});

console.log('aggregate.ts');

let voteSeq = 0;
function v(
  voter: string,
  product: string,
  vote: VoteRow['vote'],
  undo_target = '',
): VoteRow {
  voteSeq++;
  return {
    vote_id: `vid-${voteSeq}`,
    ts_iso: new Date(2026, 0, 1, 0, 0, voteSeq).toISOString(),
    voter,
    product_key: product,
    row_anchor: 2,
    vote,
    undo_target,
    session_id: 's',
  };
}

check('resolve: two voters, two rows, neither overwrites', () => {
  const votes = [v('A', 'p1', 'keep'), v('B', 'p1', 'skip')];
  const r = resolveProductVotes(votes, 'p1');
  assert.equal(r.get('A'), 'keep');
  assert.equal(r.get('B'), 'skip');
  assert.equal(r.size, 2);
});

check('resolve: undo nets out the targeted vote', () => {
  const keepA = v('A', 'p1', 'keep'); // vid-?
  const votes = [keepA, v('A', 'p1', 'undo', keepA.vote_id)];
  const r = resolveProductVotes(votes, 'p1');
  assert.equal(r.has('A'), false);
});

check('resolve: latest non-undone wins (undo then re-vote)', () => {
  const keepA = v('A', 'p1', 'keep');
  const undo = v('A', 'p1', 'undo', keepA.vote_id);
  const superA = v('A', 'p1', 'super');
  const r = resolveProductVotes([keepA, undo, superA], 'p1');
  assert.equal(r.get('A'), 'super');
});

check('verdict: 2 keeps -> BUY, no conflict, no hero', () => {
  const t = tally(resolveProductVotes([v('A', 'p', 'keep'), v('B', 'p', 'keep')], 'p'));
  assert.equal(t.net_score, 2);
  assert.equal(t.verdict, 'BUY');
  assert.equal(t.conflict, false);
  assert.equal(t.hero, false);
});

check('verdict: super-like -> hero + BUY', () => {
  const t = tally(resolveProductVotes([v('A', 'p', 'super'), v('B', 'p', 'keep')], 'p'));
  assert.equal(t.net_score, 3); // super(2) + keep(1)
  assert.equal(t.hero, true);
  assert.equal(t.verdict, 'BUY');
});

check('verdict: single skip -> SKIP', () => {
  const t = tally(resolveProductVotes([v('A', 'p', 'skip')], 'p'));
  assert.equal(t.net_score, -1);
  assert.equal(t.verdict, 'SKIP');
});

check('verdict: 1 keep 1 skip -> conflict + REVIEW', () => {
  const t = tally(resolveProductVotes([v('A', 'p', 'keep'), v('B', 'p', 'skip')], 'p'));
  assert.equal(t.net_score, 0);
  assert.equal(t.conflict, true); // min(1,1)/2 = 0.5 >= 0.34
  assert.equal(t.verdict, 'REVIEW');
});

check('verdict: 3 keep 1 skip -> minority share below threshold, BUY', () => {
  const votes = [
    v('A', 'p', 'keep'),
    v('B', 'p', 'keep'),
    v('C', 'p', 'keep'),
    v('D', 'p', 'skip'),
  ];
  const t = tally(resolveProductVotes(votes, 'p'));
  assert.equal(t.net_score, 2); // 3 - 1
  assert.equal(t.conflict, false); // min(3,1)/4 = 0.25 < 0.34
  assert.equal(t.verdict, 'BUY');
});

check('verdict: high net but conflict downgrades BUY -> REVIEW', () => {
  // 2 super + 2 skip: net = 4 - 2 = 2 (>= BUY), but min(2,2)/4 = 0.5 -> conflict
  const votes = [
    v('A', 'p', 'super'),
    v('B', 'p', 'super'),
    v('C', 'p', 'skip'),
    v('D', 'p', 'skip'),
  ];
  const t = tally(resolveProductVotes(votes, 'p'));
  assert.equal(t.net_score, 2);
  assert.equal(t.conflict, true);
  assert.equal(t.verdict, 'REVIEW');
  assert.equal(t.hero, true);
});

check('breakdown string format', () => {
  const t = tally(resolveProductVotes([v('A', 'p', 'keep'), v('B', 'p', 'super')], 'p'));
  assert.equal(t.breakdown, 'keep:1 skip:0 super:1');
});

check('resolveVoterVotes: votedKeys for resume', () => {
  const votes = [v('A', 'p1', 'keep'), v('A', 'p2', 'skip'), v('B', 'p1', 'super')];
  const mine = resolveVoterVotes(votes, 'A');
  assert.deepEqual([...mine.keys()].sort(), ['p1', 'p2']);
  assert.equal(mine.get('p1'), 'keep');
});

check('lastVoteIdFor: targets most recent non-undone vote', () => {
  const k1 = v('A', 'p', 'keep');
  const s1 = v('A', 'p', 'skip');
  const last = lastVoteIdFor([k1, s1], 'A', 'p');
  assert.equal(last?.vote_id, s1.vote_id);
  // after undoing s1, the last becomes k1
  const undo = v('A', 'p', 'undo', s1.vote_id);
  const last2 = lastVoteIdFor([k1, s1, undo], 'A', 'p');
  assert.equal(last2?.vote_id, k1.vote_id);
});

console.log(`\nAll ${passed} checks passed ✅`);
