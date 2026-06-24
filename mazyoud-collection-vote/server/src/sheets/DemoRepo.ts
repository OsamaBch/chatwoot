import type { SheetsRepo } from './SheetsRepo';
import type { Product, ResultRow, Voter, VoteRow } from './types';

/**
 * In-memory backend for previewing the app with ZERO Google setup.
 * Selected with DATA_BACKEND=demo. Seeds a handful of products + voters and
 * keeps votes in memory so the full flow (login → swipe → undo → results →
 * progress) works end-to-end. Nothing is persisted; restarting resets state.
 */
const CATEGORIES = ['Shoes', 'Bags', 'Jackets'];

function seedProducts(): Product[] {
  const out: Product[] = [];
  for (let i = 0; i < 12; i++) {
    const key = `demo-${i + 1}`;
    out.push({
      product_key: key,
      row_anchor: 2 + i * 10, // mimic the 10-row block layout
      // Local SVG (served by GET /demo-img/:seed) so the demo needs no network.
      image_url: `/demo-img/${key}`,
      price: 1500 + ((i * 437) % 5000),
      category: CATEGORIES[i % CATEGORIES.length],
      gender: i % 2 === 0 ? 'Men' : 'Women',
      title: `Demo Product ${i + 1}`,
      product_link: `https://example.com/offer/${i + 1}`,
    });
  }
  return out;
}

export class DemoRepo implements SheetsRepo {
  private products = seedProducts();
  private votes: VoteRow[] = [];
  private results = new Map<string, ResultRow>();

  async getProducts(): Promise<Product[]> {
    return this.products;
  }

  async getVoters(): Promise<Voter[]> {
    return [
      { name: 'Demo', pin: '0000', active: true },
      { name: 'Sara', pin: '1234', active: true },
      { name: 'Karim', pin: '4242', active: true },
    ];
  }

  async appendVote(v: VoteRow): Promise<void> {
    this.votes.push({ ...v });
  }

  async getVotes(): Promise<VoteRow[]> {
    return this.votes.slice();
  }

  async upsertResult(r: ResultRow): Promise<void> {
    this.results.set(r.product_key, r);
  }

  async writeInline(): Promise<void> {
    /* no-op in demo */
  }

  async ensureTabs(): Promise<void> {
    /* nothing to bootstrap in demo */
  }
}
