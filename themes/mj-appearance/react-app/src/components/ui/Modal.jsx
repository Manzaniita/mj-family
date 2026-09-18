import { AnimatePresence, motion } from 'framer-motion';
import { X } from 'lucide-react';
import { useRef } from 'react';
import { EASE } from '@/lib/format';
import { useDialog, useScrollLock } from '@/lib/a11y';
import { t } from '@/lib/copy';

export function Modal({ open, onClose, children, wide = false, label }) {
  const panel = useRef(null);
  useScrollLock(open);
  useDialog(panel, open, onClose);

  return (
    <AnimatePresence>
      {open && (
        <div className="mj-modal fixed inset-0 z-95 grid place-items-center p-4">
          <motion.div
            className="mj-modal__overlay absolute inset-0 bg-(--mj-overlay) backdrop-blur-md"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
          />
          <motion.div
            ref={panel}
            tabIndex={-1}
            className={`mj-modal__panel relative w-full ${wide ? 'max-w-4xl' : 'max-w-lg'} overflow-hidden rounded-xl3 border border-line bg-bg-soft/95 shadow-soft outline-none backdrop-blur-2xl`}
            initial={{ opacity: 0, scale: 0.94, y: 24 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.96, y: 12 }}
            transition={{ duration: 0.4, ease: EASE }}
            role="dialog"
            aria-modal="true"
            aria-label={label}
          >
            <button
              onClick={onClose}
              className="absolute right-4 top-4 z-10 grid size-9 place-items-center rounded-(--mj-radius-pill) glass text-ink-soft transition-colors hover:text-ink"
              aria-label={t('ui_close')}
            >
              <X size={17} />
            </button>
            {children}
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
}
