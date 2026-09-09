import { useRef } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/format';
import { useDialog, usePresence, useScrollLock, useUid } from '@/lib/a11y';
import { Portal } from './Portal';
import { iconBtnCls } from './styles';

/**
 * Panel lateral (pantalla completa en mobile, columna de `width` en desktop).
 * @param {{ open: boolean, onClose: () => void, title?, description?, children, footer?: ReactNode,
 *   side?: 'right'|'left', width?: string (CSS, default 28rem), dismissible?: boolean, className?, bodyClassName? }} props
 */
export function Drawer({ open, onClose, title, description, children, footer, side = 'right', width = '28rem', dismissible = true, className, bodyClassName }) {
  const panel = useRef(null);
  const { mounted, state } = usePresence(open, 340);
  const titleId = useUid('mjto-drw');
  useScrollLock(mounted);
  useDialog(panel, mounted && open, dismissible ? onClose : undefined);

  if (!mounted) return null;

  return (
    <Portal>
      <div className="mjto-drawer" data-state={state} role="presentation">
        <div className="mjto-dialog__overlay" onClick={dismissible ? onClose : undefined} aria-hidden="true" />
        <aside
          ref={panel}
          tabIndex={-1}
          role="dialog"
          aria-modal="true"
          aria-labelledby={title ? titleId : undefined}
          className={cn('mjto-drawer__panel', side === 'left' ? 'mjto-drawer__panel--left' : 'mjto-drawer__panel--right', className)}
          style={{ '--mjto-drawer-w': width }}
        >
          <header className="mjto-drawer__head mjto-hairline-b flex items-start gap-3 px-5 py-4 sm:px-6">
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
          <div className={cn('mjto-drawer__body mjto-scroll-y min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6', bodyClassName)}>{children}</div>
          {footer && (
            <footer className="mjto-drawer__foot mjto-hairline-t flex flex-wrap items-center justify-end gap-2 bg-surface px-5 py-3.5 sm:px-6">
              {footer}
            </footer>
          )}
        </aside>
      </div>
    </Portal>
  );
}
