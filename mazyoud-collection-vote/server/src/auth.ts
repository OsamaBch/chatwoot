import crypto from 'crypto';
import type { NextFunction, Request, Response } from 'express';
import jwt from 'jsonwebtoken';
import { config } from './config';
import type { Voter } from './sheets/types';

export const SESSION_COOKIE = 'mcv_session';

export interface SessionPayload {
  voter: string;
  sid: string; // per-login session id, stamped onto vote rows
}

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Express {
    interface Request {
      session?: SessionPayload;
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

/** Express middleware: require a valid session cookie. */
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
