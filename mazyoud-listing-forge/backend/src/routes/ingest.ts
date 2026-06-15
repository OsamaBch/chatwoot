import { Router } from 'express';
import multer from 'multer';
import { randomUUID } from 'node:crypto';
import { writeFile } from 'node:fs/promises';
import sharp from 'sharp';
import { normalizeImage } from '../services/normalize';
import { store, normalizedPath, previewPath } from '../services/store';
import type { ImageRecord } from '../types';

const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 60 * 1024 * 1024 } });
const router = Router();

// Accept many files; normalize each; reject non-images with a clear reason.
router.post('/', upload.array('files'), async (req, res) => {
  const files = (req.files as Express.Multer.File[] | undefined) ?? [];
  if (files.length === 0) {
    res.status(400).json({ error: 'No files uploaded' });
    return;
  }

  const images: ImageRecord[] = [];
  const rejected: { name: string; reason: string }[] = [];

  for (const f of files) {
    try {
      const norm = await normalizeImage(f.buffer, f.originalname);
      const id = randomUUID();
      await writeFile(normalizedPath(id), norm.buffer);

      const preview = await sharp(norm.buffer)
        .resize(1000, 1000, { fit: 'inside', withoutEnlargement: true })
        .jpeg({ quality: 82, mozjpeg: true })
        .toBuffer();
      await writeFile(previewPath(id), preview);

      const rec: ImageRecord = {
        id,
        originalName: f.originalname,
        sourceWidth: norm.width,
        sourceHeight: norm.height,
        sourceFormat: norm.format,
        previewUrl: `/files/preview/${id}.jpg`,
        status: 'queued',
        warnings: norm.warnings,
        flags: [],
        aiUsed: false,
        needsReview: norm.warnings.length > 0,
      };
      store.put(rec);
      images.push(rec);
    } catch (e) {
      rejected.push({ name: f.originalname, reason: e instanceof Error ? e.message : 'Unreadable file' });
    }
  }

  res.json({ images, rejected });
});

export default router;
