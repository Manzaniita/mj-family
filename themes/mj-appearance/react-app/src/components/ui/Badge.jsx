import { cn } from '@/lib/format';

const tones = {
  neutral: 'mj-badge--neutral bg-surface-2 text-ink-soft border border-line',
  accent: 'mj-badge--accent bg-accent-soft text-accent border border-accent/20',
  success: 'mj-badge--success bg-success/10 text-success border border-success/20',
  danger: 'mj-badge--danger bg-danger/10 text-danger border border-danger/20',
  solid: 'mj-badge--solid bg-accent text-white',
};

export function Badge({ tone = 'neutral', className, children }) {
  return (
    <span
      className={cn(
        'mj-badge inline-flex items-center gap-1 rounded-(--mj-radius-badge) px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider backdrop-blur-md',
        tones[tone],
        className
      )}
    >
      {children}
    </span>
  );
}
