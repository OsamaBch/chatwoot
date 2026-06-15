import 'dotenv/config';
import express from 'express';
import cors from 'cors';
import { ensureDirs, DATA_DIR, clearData } from './services/store';
import configRoute from './routes/config';
import ingestRoute from './routes/ingest';
import generateRoute from './routes/generate';
import exportRoute from './routes/export';

ensureDirs();

const app = express();
app.use(cors());
app.use(express.json({ limit: '2mb' }));

app.get('/api/health', (_req, res) => res.json({ ok: true }));
app.use('/api/config', configRoute);
app.use('/api/ingest', ingestRoute);
app.use('/api/generate', generateRoute);
app.use('/api/export', exportRoute);

// "New batch" — wipe working files + state. (Frontend calls this only when safe.)
app.post('/api/reset', (_req, res) => {
  clearData();
  res.json({ ok: true });
});

// Serve working images (previews + processed) for the before/after cards.
app.use('/files', express.static(DATA_DIR, { etag: false, maxAge: 0 }));

const port = Number(process.env.PORT || 5174);
app.listen(port, () => {
  // eslint-disable-next-line no-console
  console.log(`[mazyoud-listing-forge] backend listening on http://localhost:${port}`);
});
