export type ImageStatus = 'queued' | 'cleaning' | 'framing' | 'review' | 'done' | 'failed';

export interface ImageRecord {
  id: string;
  originalName: string;
  sourceWidth: number;
  sourceHeight: number;
  sourceFormat: string;
  previewUrl: string;
  processedUrl?: string;
  status: ImageStatus;
  warnings: string[];
  flags: string[];
  aiUsed: boolean;
  needsReview: boolean;
  outputWidth?: number;
  outputHeight?: number;
  outputBytes?: number;
  outputQuality?: number;
  error?: string;
}

export interface PipelineConfig {
  aiProvider: 'gemini' | 'openai';
  geminiModelId: string;
  openaiModelId: string;
  aspectRatio: string;
  outputWidth: number;
  outputHeight: number;
  negativeSpaceRatio: number;
  backgroundColor: string;
  outputFormat: string;
  jpegMaxKB: number;
  jpegQualityFloor: number;
  jpegQualityStart: number;
  filenameSeparator: string;
  fileExtension: string;
  concurrency: number;
  minSourceLongSide: number;
  maxOutpaintFraction: number;
  fidelityDiffThreshold: number;
  enableBackblaze: boolean;
  enableWooUpload: boolean;
}

export type ConflictPolicy = 'overwrite' | 'skip' | 'version';

export type AiProviderName = 'gemini' | 'openai';
export type KeySource = 'saved' | 'env' | 'none';
export interface KeyStatus {
  hasKey: boolean;
  source: KeySource;
}

export interface AppSettings {
  provider: AiProviderName;
  geminiModelId: string;
  openaiModelId: string;
  keys: { gemini: KeyStatus; openai: KeyStatus };
  pricing: { geminiPerImageUSD: number; openaiPerImageUSD: number };
  openaiMaxLongSide: number;
}

export interface Estimate {
  images: number;
  provider: AiProviderName;
  aiCalls: number;
  estCostUSD: number;
  perImageUSD: number;
  requiresConfirm: boolean;
  note?: string;
}

export interface CostTally {
  aiCalls: number;
  costUSD: number;
}

export interface UsageRun {
  ts: string;
  sku: string;
  provider: AiProviderName;
  images: number;
  aiCalls: number;
  costUSD: number;
}

export interface ProviderTotals {
  aiCalls: number;
  costUSD: number;
}

export interface Usage {
  totals: { runs: number; images: number; aiCalls: number; costUSD: number };
  byProvider: Record<AiProviderName, ProviderTotals>;
  runs: UsageRun[];
}
