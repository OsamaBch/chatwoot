import type { Request, Response } from 'express';
import { config } from './config';

interface CacheEntry {
  buf: Buffer;
  contentType: string;
  bytes: number;
}

/**
 * Tiny in-memory LRU for proxied images. Keyed by upstream URL. Evicts by
 * count and by total byte budget. Optional but keeps repeat card views snappy
 * and reduces upstream hits.
 */
class ImageLru {
  private map = new Map<string, CacheEntry>();
  private totalBytes = 0;

  get(key: string): CacheEntry | undefined {
    const entry = this.map.get(key);
    if (!entry) return undefined;
    // refresh recency
    this.map.delete(key);
    this.map.set(key, entry);
    return entry;
  }

  set(key: string, entry: CacheEntry): void {
    if (entry.bytes > config.imgCacheMaxBytes) return; // too big to cache
    const prev = this.map.get(key);
    if (prev) this.totalBytes -= prev.bytes;
    this.map.set(key, entry);
    this.totalBytes += entry.bytes;
    this.evict();
  }

  private evict(): void {
    while (
      this.map.size > config.imgCacheMaxEntries ||
      this.totalBytes > config.imgCacheMaxBytes
    ) {
      const oldest = this.map.keys().next().value as string | undefined;
      if (oldest === undefined) break;
      const e = this.map.get(oldest);
      if (e) this.totalBytes -= e.bytes;
      this.map.delete(oldest);
    }
  }
}

const cache = new ImageLru();

/** Allowlist check: exact host, or suffix entries that begin with a dot. */
export function isHostAllowed(hostname: string): boolean {
  const h = hostname.toLowerCase();
  return config.imgAllowedHosts.some((entry) => {
    if (!entry) return false;
    if (entry.startsWith('.')) {
      const bare = entry.slice(1);
      return h === bare || h.endsWith(entry);
    }
    return h === entry;
  });
}

export async function imageProxyHandler(req: Request, res: Response): Promise<void> {
  const raw = req.query.u;
  if (typeof raw !== 'string' || !raw) {
    res.status(400).json({ error: 'missing u' });
    return;
  }

  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    res.status(400).json({ error: 'invalid url' });
    return;
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    res.status(400).json({ error: 'unsupported protocol' });
    return;
  }
  if (!isHostAllowed(url.hostname)) {
    res.status(403).json({ error: 'host not allowed' });
    return;
  }

  const key = url.toString();
  const cached = cache.get(key);
  if (cached) {
    sendImage(res, cached, true);
    return;
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), config.imgFetchTimeoutMs);
  try {
    const upstream = await fetch(url, {
      signal: controller.signal,
      headers: {
        // A neutral UA/referer sidesteps alicdn hotlink protection.
        'user-agent':
          'Mozilla/5.0 (compatible; MazyoudVote/1.0; +https://vote.mazyoud.com)',
        accept: 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        referer: `${url.protocol}//${url.hostname}/`,
      },
    });

    if (!upstream.ok) {
      res.status(502).json({ error: `upstream ${upstream.status}` });
      return;
    }

    const contentType = upstream.headers.get('content-type') ?? 'application/octet-stream';
    if (!contentType.toLowerCase().startsWith('image/')) {
      res.status(415).json({ error: 'not an image' });
      return;
    }

    const arrayBuf = await upstream.arrayBuffer();
    const buf = Buffer.from(arrayBuf);
    const entry: CacheEntry = { buf, contentType, bytes: buf.byteLength };
    cache.set(key, entry);
    sendImage(res, entry, false);
  } catch (err) {
    const aborted = (err as { name?: string })?.name === 'AbortError';
    res.status(aborted ? 504 : 502).json({
      error: aborted ? 'upstream timeout' : 'fetch failed',
    });
  } finally {
    clearTimeout(timeout);
  }
}

function sendImage(res: Response, entry: CacheEntry, hit: boolean): void {
  res.setHeader('Content-Type', entry.contentType);
  res.setHeader('Content-Length', String(entry.bytes));
  // Long, immutable cache — image URLs are content-addressed on the CDN side.
  res.setHeader('Cache-Control', 'public, max-age=604800, immutable');
  res.setHeader('X-Cache', hit ? 'HIT' : 'MISS');
  res.end(entry.buf);
}
