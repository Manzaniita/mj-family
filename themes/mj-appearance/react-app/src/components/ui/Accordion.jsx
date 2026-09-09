import { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Plus } from 'lucide-react';
import { EASE } from '@/lib/format';

export function Accordion({ items }) {
  const [open, setOpen] = useState(0);
  return (
    <div className="mj-accordion divide-y divide-line rounded-xl3 border border-line glass overflow-hidden">
      {items.map((item, i) => {
        const isOpen = open === i;
        return (
          <div key={i} className="mj-accordion__item">
            <button
              onClick={() => setOpen(isOpen ? -1 : i)}
              aria-expanded={isOpen}
              className="mj-accordion__trigger flex w-full items-center justify-between gap-4 px-6 py-5 text-left transition-colors hover:bg-surface"
            >
              <span className="mj-accordion__q font-display text-base font-medium tracking-tight">{item.q}</span>
              <motion.span
                animate={{ rotate: isOpen ? 45 : 0 }}
                transition={{ duration: 0.3, ease: EASE }}
                className="mj-accordion__icon grid size-7 shrink-0 place-items-center rounded-(--mj-radius-pill) bg-surface-2 text-ink-soft"
              >
                <Plus size={15} />
              </motion.span>
            </button>
            <AnimatePresence initial={false}>
              {isOpen && (
                <motion.div
                  initial={{ height: 0, opacity: 0 }}
                  animate={{ height: 'auto', opacity: 1 }}
                  exit={{ height: 0, opacity: 0 }}
                  transition={{ duration: 0.4, ease: EASE }}
                  className="overflow-hidden"
                >
                  <p className="mj-accordion__a px-6 pb-6 text-sm leading-relaxed text-ink-soft">{item.a}</p>
                </motion.div>
              )}
            </AnimatePresence>
          </div>
        );
      })}
    </div>
  );
}
