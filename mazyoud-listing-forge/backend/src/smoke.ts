/**
 * End-to-end smoke test for the deterministic pipeline (no AI, no server).
 * Run: npm run smoke   (from backend/ or via root `npm run smoke`)
 */
import { writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';
import { normalizeImage } from './services/normalize';
import { frameImage } from './services/framing';
import { slugifySku, assignNames } from './services/naming';
import { toCsv } from './services/manifest';
import { config } from './config';
import { PROJECT_ROOT } from './services/store';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`ASSERT FAILED: ${msg}`);
}

async function main(): Promise<void> {
  // Synthetic "product photo": a coral garment-ish rectangle on an off-white field.
  const product = await sharp({ create: { width: 700, height: 1000, channels: 3, background: '#FA5B4E' } })
    .png()
    .toBuffer();
  const src = await sharp({ create: { width: 1200, height: 1500, channels: 3, background: '#f2f2f0' } })
    .composite([{ input: product, left: 250, top: 250 }])
    .png()
    .toBuffer();

  const norm = await normalizeImage(src, 'sample.png');
  console.log('normalize →', `${norm.width}×${norm.height}`, 'format:', norm.format, 'warnings:', norm.warnings);
  assert(norm.width === 1200 && norm.height === 1500, 'normalize preserves dimensions');

  const framed = await frameImage(norm.buffer);
  const meta = await sharp(framed.buffer).metadata();
  console.log(
    'frame →',
    `${meta.width}×${meta.height}`,
    `${Math.round(framed.bytes / 1024)}KB`,
    `q${framed.quality}`,
    'flags:',
    framed.flags,
  );
  assert(meta.width === config.outputWidth && meta.height === config.outputHeight, 'output is exact 6:7 canvas');
  assert(meta.format === 'jpeg', 'output is JPEG');
  assert(framed.bytes <= config.jpegMaxKB * 1024 || framed.quality === config.jpegQualityFloor, 'size target or floor respected');

  const slug = slugifySku('Robe 2025 / 014!');
  console.log('slugify("Robe 2025 / 014!") →', slug);
  assert(slug.slug === 'Robe-2025-014', `slug should strip illegal chars (got ${slug.slug})`);
  assert(slug.changed === true, 'slug change is reported');

  const names = assignNames('Robe 2025 / 014!', 3);
  console.log('names →', names);
  assert(names[0] === 'Robe-2025-014.jpg', 'hero has no index');
  assert(names[1] === 'Robe-2025-014-1.jpg' && names[2] === 'Robe-2025-014-2.jpg', 'contiguous numbering');

  const csv = toCsv([
    { sku: slug.slug, filename: names[0], order: 0, source: 'sample.png', status: 'done', ai_used: false, flags: '' },
  ]);
  assert(csv.split('\n')[0] === 'SKU,filename,order,source,status,ai_used,flags', 'manifest header');

  const out = path.join(PROJECT_ROOT, 'output', '_smoke');
  await mkdir(out, { recursive: true });
  await writeFile(path.join(out, names[0]), framed.buffer);
  await writeFile(path.join(out, `${slug.slug}_manifest.csv`), csv);
  console.log('wrote sample →', path.join(out, names[0]));
  console.log('\nSMOKE OK ✓');
}

main().catch((e) => {
  console.error('\nSMOKE FAILED ✗\n', e);
  process.exit(1);
});
