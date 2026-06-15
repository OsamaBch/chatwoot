import type { Estimate } from '../types';

interface Props {
  estimate: Estimate;
  onConfirm: () => void;
  onCancel: () => void;
}

export default function ConfirmBatchModal({ estimate, onConfirm, onCancel }: Props) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-charcoal/40 p-6" onClick={onCancel}>
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold">Run this batch?</h2>
        <dl className="my-4 space-y-1 text-sm">
          <Line label="Images" value={String(estimate.images)} />
          <Line label="Provider" value={estimate.provider} />
          <Line label="Estimated AI calls" value={String(estimate.aiCalls)} />
          <Line
            label="Estimated cost"
            value={estimate.aiCalls > 0 ? `~$${estimate.estCostUSD.toFixed(2)} ($${estimate.perImageUSD}/img)` : '$0.00'}
          />
        </dl>
        {estimate.note && <p className="mb-4 rounded-lg bg-neutral-50 p-2 text-[11px] text-neutral-500">{estimate.note}</p>}
        <div className="flex justify-end gap-2">
          <button className="btn-ghost" onClick={onCancel}>
            Cancel
          </button>
          <button className="btn-primary" onClick={onConfirm}>
            Generate {estimate.images}
          </button>
        </div>
      </div>
    </div>
  );
}

function Line({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <dt className="text-neutral-500">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  );
}
