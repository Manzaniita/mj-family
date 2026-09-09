import { ArrowLeft } from 'lucide-react';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/format';
import { iconBtnCls } from './styles';

/**
 * Cabecera de una vista del panel: eyebrow, título, subtítulo, acciones y "volver".
 * @param {{ title: ReactNode, subtitle?: ReactNode, eyebrow?: string, actions?: ReactNode, back?: string (ruta) | (() => void),
 *   backLabel?: string, children?: ReactNode (fila extra debajo: filtros, tabs), className? }} props
 */
export function PageHeader({ title, subtitle, eyebrow, actions, back, backLabel = 'Volver', children, className }) {
  const BackEl = back
    ? typeof back === 'function'
      ? (props) => <button type="button" onClick={back} {...props} />
      : (props) => <Link to={back} {...props} />
    : null;
  return (
    <header className={cn('mjto-page-head mb-4 flex flex-col gap-3', className)}>
      <div className="flex items-start gap-3">
        {BackEl && (
          <BackEl className={cn(iconBtnCls, '-ml-2 mt-0.5 size-9')} aria-label={backLabel}>
            <ArrowLeft size={19} aria-hidden="true" />
          </BackEl>
        )}
        <div className="min-w-0 flex-1">
          {eyebrow && <p className="mb-0.5 text-[11px] font-bold uppercase tracking-[0.14em] text-accent">{eyebrow}</p>}
          <h1 className="mjto-balance font-display text-[1.65rem] font-bold leading-[1.1] tracking-tight text-ink sm:text-3xl">{title}</h1>
          {subtitle && <p className="mt-1 text-sm leading-snug text-ink-soft">{subtitle}</p>}
        </div>
        {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
      </div>
      {children}
    </header>
  );
}

/** Título de sección dentro de una vista (con acción opcional a la derecha). */
export function SectionTitle({ title, action, className }) {
  return (
    <div className={cn('mjto-section-title mb-2 flex items-center justify-between gap-3', className)}>
      <h2 className="text-[11px] font-bold uppercase tracking-[0.14em] text-ink-faint">{title}</h2>
      {action}
    </div>
  );
}
