import { useRef } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/format';
import { useDialog, usePresence, useScrollLock, useUid } from '@/lib/a11y';
import { Portal } from './Portal';
import { iconBtnCls } from './styles';

const widths = {
  sm: '24rem',
  md: '32rem',
  lg: '44rem',
  xl: '60rem',
};

/**
 * Diálogo modal: hoja desde abajo en mobile, centrado en desktop (≥ 40rem).
 * Foco atrapado, Escape y click afuera cierran (si `dismissible`), scroll del body bloqueado.
 * @param {{ open: boolean, onClose: () => void, title?, description?, children, footer?: ReactNode,
 *   size?: 'sm'|'md'|'lg'|'xl', dismissible?: boolean, label?: string (aria-label si no hay título),
 *   className? (panel), bodyClassName?, noPadding?: boolean }} props
 */
export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  dismissible = true,
  label,
  className,
  bodyClassName,
  noPadding = false,
}) {
  const panel = useRef(null);
  const { mounted, state } = usePresence(open, 260);
  const titleId = useUid('mjto-dlg');
  useScrollLock(mounted);
  useDialog(panel, mounted && open, dismissible ? onClose : undefined);

  if (!mounted) return null;

  return (
    <Portal>
      <div className="mjto-dialog" data-state={state} role="presentation">
        <div className="mjto-dialog__overlay" onClick={dismissible ? onClose : undefined} aria-hidden="true" />
        <div
          ref={panel}
          tabIndex={-1}
          role="dialog"
          aria-modal="true"
          aria-labelledby={title ? titleId : undefined}
          aria-label={!title ? label : undefined}
          className={cn('mjto-dialog__panel', className)}
          style={{ '--mjto-dialog-w': widths[size] || widths.md }}
        >
          <div className="mjto-dialog__grip" aria-hidden="true" />
          {(title || description || dismissible) && (
            <header className="mjto-dialog__head flex items-start gap-3 px-5 pb-3 pt-4 sm:px-6 sm:pt-5">
              <div className="min-w-0 flex-1">
                {title && (
                  <h2 id={titleId} className="font-display text-lg font-semibold leading-tight tracking-tight text-ink">
                    {title}
                  </h2>
                )}
                {description && <p className="mt-1 text-sm leading-snug text-ink-soft">{description}</p>}
              </div>
              {dismissible && (
                <button type="button" onClick={onClose} className={cn(iconBtnCls, '-mr-2 -mt-1 size-9')} aria-label="Cerrar" data-no-autofocus="1">
                  <X size={18} aria-hidden="true" />
                </button>
              )}
            </header>
          )}
          <div
            className={cn(
              'mjto-dialog__body mjto-scroll-y min-h-0 flex-1 overflow-y-auto',
              !noPadding && 'px-5 pb-5 sm:px-6',
              !title && !description && !dismissible && !noPadding && 'pt-5',
              bodyClassName
            )}
          >
            {children}
          </div>
          {footer && (
            <footer className="mjto-dialog__foot mjto-hairline-t flex flex-wrap items-center justify-end gap-2 bg-surface px-5 py-3.5 sm:px-6">
              {footer}
            </footer>
          )}
        </div>
      </div>
    </Portal>
  );
}
