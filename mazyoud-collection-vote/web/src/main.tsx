import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

// Note: no React.StrictMode. Its dev-only double-mount re-runs effects and
// remounts components, which destabilizes react-tinder-card's gesture binding
// (cards getting "stuck"). StrictMode only affects dev, so this is purely a
// dev-stability choice.
ReactDOM.createRoot(document.getElementById('root')!).render(<App />);

// Service worker: register ONLY in production builds. In dev a cached SW serves
// stale assets and breaks HMR/logins, so we actively unregister any that linger
// (this self-heals browsers that registered it on an earlier dev load).
if ('serviceWorker' in navigator) {
  if (import.meta.env.PROD) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js').catch(() => {
        /* offline support is optional; ignore failures */
      });
    });
  } else {
    navigator.serviceWorker
      .getRegistrations()
      .then((regs) => regs.forEach((r) => r.unregister()))
      .catch(() => {});
    if (window.caches) {
      caches.keys().then((keys) => keys.forEach((k) => caches.delete(k))).catch(() => {});
    }
  }
}
