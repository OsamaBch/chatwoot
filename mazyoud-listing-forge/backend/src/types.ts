export type ImageStatus =
  | 'queued'
  | 'cleaning' // AI clean-up (phase 3)
  | 'framing'
  | 'review'   // framed OK but flagged — surfaces in the Review queue
  | 'done'
  | 'failed';

/** A single image moving through the pipeline. Lives in the in-memory store. */
export interface ImageRecord {
  id: string;
  originalName: string;

  // source (post-normalize) facts
  sourceWidth: number;
  sourceHeight: number;
  sourceFormat: string;

  // served working files (under /files)
  previewUrl: string; // "before" (downscaled normalized)
  processedUrl?: string; // "after" (final framed 6:7)

  status: ImageStatus;
  warnings: string[]; // ingest-time: low-res, looks-like-a-chart, …
  flags: string[]; // process-time: upscaled-source, over-size, …
  aiUsed: boolean;
  needsReview: boolean;

  // output facts (after framing)
  outputWidth?: number;
  outputHeight?: number;
  outputBytes?: number;
  outputQuality?: number;

  error?: string;
}
