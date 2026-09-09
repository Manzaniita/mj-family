import { cn } from '@/lib/format';

const labelCls = 'mj-field__label mb-1.5 block text-xs font-medium uppercase tracking-wider text-ink-faint';
const fieldCls =
  'mj-input w-full rounded-(--mj-radius-input) border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-faint transition-colors focus:border-accent/60 focus:bg-surface-2 outline-none';

export function Input({ label, className, id, ...rest }) {
  return (
    <label className="mj-field block">
      {label && <span className={labelCls}>{label}</span>}
      <input id={id} className={cn(fieldCls, 'h-11', className)} {...rest} />
    </label>
  );
}

export function Select({ label, className, id, children, ...rest }) {
  return (
    <label className="mj-field block">
      {label && <span className={labelCls}>{label}</span>}
      <select id={id} className={cn(fieldCls, 'h-11 disabled:opacity-50', className)} {...rest}>
        {children}
      </select>
    </label>
  );
}

export function Textarea({ label, className, ...rest }) {
  return (
    <label className="mj-field block">
      {label && <span className={labelCls}>{label}</span>}
      <textarea className={cn(fieldCls, 'min-h-32 py-3', className)} {...rest} />
    </label>
  );
}
