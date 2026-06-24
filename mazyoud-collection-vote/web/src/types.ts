export type Vote = 'keep' | 'skip' | 'super';
export type Verdict = 'BUY' | 'SKIP' | 'REVIEW';

export interface AppConfig {
  showDetails: boolean;
  allowRevote: boolean;
  appName: string;
}

export interface ProductCard {
  product_key: string;
  row_anchor: number;
  image: string | null; // proxied path: /img?u=...
  price: number | null;
  category: string;
  gender: string;
  title: string;
  color?: string;
  remark?: string;
  notes?: string;
  myVote: Vote | null;
}

export interface Aggregate {
  product_key: string;
  row_anchor: number;
  title: string;
  price: number | null;
  category: string;
  gender: string;
  image_url: string;
  keep: number;
  skip: number;
  super: number;
  voters: number;
  net_score: number;
  verdict: Verdict;
  conflict: boolean;
  hero: boolean;
  last_updated: string;
}

export interface Progress {
  totalProducts: number;
  perVoter: { voter: string; voted: number; remaining: number }[];
  fullyDecided: number;
  leaderboard: { voter: string; voted: number }[];
}
