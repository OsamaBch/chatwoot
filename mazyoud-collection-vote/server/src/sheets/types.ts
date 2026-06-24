import type { Verdict, VoteRowValue } from '../config';

/** A product parsed from an anchor row of the source tab (AID 1). */
export interface Product {
  product_key: string; // trimmed col M, or `row-<anchor>` fallback
  row_anchor: number; // 1-based sheet row number of the anchor
  image_url: string | null; // extracted from =IMAGE("...") in col B
  price: number | null; // cached numeric value of col C
  color?: string;
  remark?: string;
  category?: string;
  gender?: string;
  notes?: string;
  title?: string;
  supplier?: string;
  supplier_link?: string;
  product_link?: string;
}

/** A login row from the Voters tab. */
export interface Voter {
  name: string;
  pin: string; // plain or salted-sha256 depending on HASH_PINS
  active: boolean;
}

/** A single append-only row in the Votes tab. */
export interface VoteRow {
  vote_id: string;
  ts_iso: string;
  voter: string;
  product_key: string;
  row_anchor: number;
  vote: VoteRowValue; // keep | skip | super | undo
  undo_target: string; // vote_id being undone (undo rows only), else ''
  session_id: string;
}

/** A computed aggregate row in the Results tab (one per product). */
export interface ResultRow {
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
