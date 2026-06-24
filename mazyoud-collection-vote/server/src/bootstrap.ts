import { config } from './config';
import { GoogleSheetsRepo } from './sheets/GoogleSheetsRepo';
import { N8nWebhookRepo } from './sheets/N8nWebhookRepo';
import type { SheetsRepo } from './sheets/SheetsRepo';

/** Construct the configured storage backend. */
export function createRepo(): SheetsRepo {
  if (config.dataBackend === 'n8n') {
    console.log('[bootstrap] DATA_BACKEND=n8n -> N8nWebhookRepo');
    return new N8nWebhookRepo();
  }
  console.log('[bootstrap] DATA_BACKEND=google -> GoogleSheetsRepo');
  return new GoogleSheetsRepo();
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
