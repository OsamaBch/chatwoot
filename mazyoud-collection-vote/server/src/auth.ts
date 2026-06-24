import crypto from 'crypto';
import type { NextFunction, Request, Response } from 'express';
import jwt from 'jsonwebtoken';
import { config } from './config';
import type { Voter } from './sheets/types';

export const SESSION_COOKIE = 'mcv_session';
export const ADMIN_COOKIE = 'mcv_admin';

export interface SessionPayload {
  voter: string;
  sid: string; // per-login session id, stamped onto vote rows
}

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Express {
    interface Request {
      session?: SessionPayload;
      isAdmin?: boolean;
    }
  }
}

export function newSessionId(): string {
  return crypto.randomUUID();
}

export function signSession(payload: SessionPayload): string {
  return jwt.sign(payload, config.sessionSecret, {
    expiresIn: config.sessionTtlSeconds,
  });
}

export function verifySession(token: string): SessionPayload | null {
  try {
    const decoded = jwt.verify(token, config.sessionSecret) as jwt.JwtPayload;
    if (typeof decoded.voter === 'string' && typeof decoded.sid === 'string') {
      return { voter: decoded.voter, sid: decoded.sid };
    }
    return null;
  } catch {
    return null;
  }
}

export function setSessionCookie(res: Response, token: string): void {
  res.cookie(SESSION_COOKIE, token, {
    httpOnly: true,
    sameSite: 'lax',
    secure: config.appOrigin.startsWith('https://'),
    maxAge: config.sessionTtlSeconds * 1000,
    path: '/',
  });
}

export function clearSessionCookie(res: Response): void {
  res.clearCookie(SESSION_COOKIE, { path: '/' });
}

/**
 * Constant-time-ish PIN verification.
 *  - HASH_PINS=false (default): plain comparison (acceptable for a private,
 *    internal tool with a private sheet).
 *  - HASH_PINS=true: the stored value is "salt$sha256hex" where
 *    sha256hex = sha256(salt + pin). See the README for generating hashes.
 */
export function checkPin(input: string, stored: string): boolean {
  const candidate = (input ?? '').trim();
  const expected = (stored ?? '').trim();
  if (!candidate || !expected) return false;

  if (config.hashPins) {
    const sep = expected.indexOf('$');
    if (sep <= 0) return false;
    const salt = expected.slice(0, sep);
    const hash = expected.slice(sep + 1);
    const computed = crypto
      .createHash('sha256')
      .update(salt + candidate)
      .digest('hex');
    return timingSafeEqual(computed, hash);
  }
  return timingSafeEqual(candidate, expected);
}

function timingSafeEqual(a: string, b: string): boolean {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  if (ab.length !== bb.length) return false;
  return crypto.timingSafeEqual(ab, bb);
}

/** Find an active voter and validate the PIN. */
export function authenticateVoter(
  voters: Voter[],
  name: string,
  pin: string,
): Voter | null {
  const wanted = (name ?? '').trim().toLowerCase();
  const voter = voters.find((v) => v.name.trim().toLowerCase() === wanted && v.active);
  if (!voter) return null;
  return checkPin(pin, voter.pin) ? voter : null;
}

/** Express middleware: require a valid voter session cookie. */
export function requireAuth(req: Request, res: Response, next: NextFunction): void {
  const token = req.cookies?.[SESSION_COOKIE];
  if (!token) {
    res.status(401).json({ error: 'unauthorized' });
    return;
  }
  const session = verifySession(token);
  if (!session) {
    res.status(401).json({ error: 'unauthorized' });
    return;
  }
  req.session = session;
  next();
}

// ---------------------------------------------------------------------------
// Admin auth (dashboard: upload sheet / manage voters / export results)
// ---------------------------------------------------------------------------
export function adminConfigured(): boolean {
  return config.adminPassword.length > 0;
}

export function checkAdminPassword(input: string): boolean {
  if (!adminConfigured()) return false;
  return timingSafeEqual((input ?? '').trim(), config.adminPassword.trim());
}

export function signAdmin(): string {
  return jwt.sign({ role: 'admin' }, config.sessionSecret, {
    expiresIn: config.sessionTtlSeconds,
  });
}

export function verifyAdmin(token: string): boolean {
  try {
    const decoded = jwt.verify(token, config.sessionSecret) as jwt.JwtPayload;
    return decoded.role === 'admin';
  } catch {
    return false;
  }
}

export function setAdminCookie(res: Response, token: string): void {
  res.cookie(ADMIN_COOKIE, token, {
    httpOnly: true,
    sameSite: 'lax',
    secure: config.appOrigin.startsWith('https://'),
    maxAge: config.sessionTtlSeconds * 1000,
    path: '/',
  });
}

export function clearAdminCookie(res: Response): void {
  res.clearCookie(ADMIN_COOKIE, { path: '/' });
}

export function isAdminRequest(req: Request): boolean {
  const token = req.cookies?.[ADMIN_COOKIE];
  return !!token && verifyAdmin(token);
}

/** Require a valid admin session. */
export function requireAdmin(req: Request, res: Response, next: NextFunction): void {
  if (!adminConfigured()) {
    res.status(503).json({ error: 'admin_not_configured' });
    return;
  }
  if (!isAdminRequest(req)) {
    res.status(401).json({ error: 'unauthorized' });
    return;
  }
  req.isAdmin = true;
  next();
}

/** Allow either a voter session or an admin session (read-only shared views). */
export function requireAnyAuth(req: Request, res: Response, next: NextFunction): void {
  const token = req.cookies?.[SESSION_COOKIE];
  const session = token ? verifySession(token) : null;
  if (session) {
    req.session = session;
    next();
    return;
  }
  if (isAdminRequest(req)) {
    req.isAdmin = true;
    next();
    return;
  }
  res.status(401).json({ error: 'unauthorized' });
}

/**
 * Produce the value to store in a voter's `pin` field. With HASH_PINS the PIN
 * is salted-sha256'd ("salt$hash"); otherwise it is stored as-is.
 */
export function preparePin(pin: string): string {
  const clean = (pin ?? '').trim();
  if (!config.hashPins) return clean;
  const salt = crypto.randomBytes(6).toString('hex');
  const hash = crypto.createHash('sha256').update(salt + clean).digest('hex');
  return `${salt}$${hash}`;
}
