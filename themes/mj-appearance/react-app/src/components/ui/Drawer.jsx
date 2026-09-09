import { AnimatePresence, motion } from 'framer-motion';
import { X } from 'lucide-react';
import { useRef } from 'react';
import { EASE } from '@/lib/format';
import { useDialog, useScrollLock } from '@/lib/a11y';

export function Drawer({ open, onClose, title, children, footer, side = 'right' }) {
  const panel = useRef(null);
  useScrollLock(open);
  useDialog(panel, open, onClose);

  return (
    <AnimatePresence>
      {open && (
        <div className="mj-drawer fixed inset-0 z-90">
          <motion.div
            className="mj-drawer__overlay absolute inset-0 bg-(--mj-overlay) backdrop-blur-sm"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
          />
          <motion.aside
            ref={panel}
            tabIndex={-1}
            className={`mj-drawer__panel absolute top-0 bottom-0 ${side === 'right' ? 'right-0' : 'left-0'} flex w-full max-w-md flex-col border-l border-line bg-bg-soft/95 outline-none backdrop-blur-2xl`}
            initial={{ x: side === 'right' ? '100%' : '-100%' }}
            animate={{ x: 0 }}
            exit={{ x: side === 'right' ? '100%' : '-100%' }}
            transition={{ duration: 0.45, ease: EASE }}
            role="dialog"
            aria-modal="true"
            aria-label={typeof title === 'string' ? title : undefined}
          >
            <div className="mj-drawer__head flex items-center justify-between px-6 py-5 hairline-b">
              <h2 className="font-display text-lg font-semibold tracking-tight">{title}</h2>
              <button
                onClick={onClose}
                className="grid size-9 place-items-center rounded-(--mj-radius-pill) glass text-ink-soft transition-colors hover:text-ink"
                aria-label="Cerrar"
              >
                <X size={17} />
              </button>
            </div>
            <div className="mj-drawer__body flex-1 overflow-y-auto px-6 py-5">{children}</div>
            {footer && <div className="mj-drawer__foot px-6 py-5 hairline-t bg-surface">{footer}</div>}
          </motion.aside>
        </div>
      )}
    </AnimatePresence>
  );
}
