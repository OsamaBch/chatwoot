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
