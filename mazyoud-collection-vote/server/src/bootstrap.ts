import { config } from './config';
import { DemoRepo } from './sheets/DemoRepo';
import { GoogleSheetsRepo } from './sheets/GoogleSheetsRepo';
import { N8nWebhookRepo } from './sheets/N8nWebhookRepo';
import type { SheetsRepo } from './sheets/SheetsRepo';
import { XlsxRepo } from './store/XlsxRepo';

/** Construct the configured storage backend. */
export function createRepo(): SheetsRepo {
  switch (config.dataBackend) {
    case 'google':
      console.log('[bootstrap] DATA_BACKEND=google -> GoogleSheetsRepo');
      return new GoogleSheetsRepo();
    case 'n8n':
      console.log('[bootstrap] DATA_BACKEND=n8n -> N8nWebhookRepo');
      return new N8nWebhookRepo();
    case 'demo':
      console.log('[bootstrap] DATA_BACKEND=demo -> DemoRepo (in-memory, no setup)');
      return new DemoRepo();
    case 'xlsx':
    default:
      console.log('[bootstrap] DATA_BACKEND=xlsx -> XlsxRepo (upload + local store)');
      return new XlsxRepo();
  }
}

/**
 * Startup bootstrap:
 *  1) ensure managed tabs (Voters/Votes/Results) + headers exist
 *  2) warm the product cache so the first GET /api/products is instant
 */
export async function bootstrap(repo: SheetsRepo): Promise<void> {
  console.log('[bootstrap] ensuring managed tabs...');
  await repo.ensureTabs();

  console.log('[bootstrap] warming product cache...');
  const products = await repo.getProducts(true);
  console.log(`[bootstrap] loaded ${products.length} products from "${config.productsTab}"`);
}
