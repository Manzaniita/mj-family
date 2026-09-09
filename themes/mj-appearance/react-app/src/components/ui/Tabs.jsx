import { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { EASE } from '@/lib/format';

export function Tabs({ tabs }) {
  const [active, setActive] = useState(0);
  return (
    <div className="mj-tabs">
      <div
        className="mj-tabs__list flex gap-1 overflow-x-auto no-scrollbar rounded-(--mj-radius-pill) glass p-1 w-fit max-w-full"
        role="tablist"
      >
        {tabs.map((t, i) => (
          <button
            key={t.label}
            role="tab"
            aria-selected={i === active}
            onClick={() => setActive(i)}
            className={`mj-tabs__tab relative rounded-(--mj-radius-pill) px-5 py-2 text-sm font-medium whitespace-nowrap transition-colors ${
              i === active ? 'mj-tabs__tab--active text-white' : 'text-ink-soft hover:text-ink'
            }`}
          >
            {i === active && (
              <motion.span
                layoutId="tab-pill"
                className="mj-tabs__pill absolute inset-0 rounded-(--mj-radius-pill) bg-accent"
                transition={{ duration: 0.35, ease: EASE }}
              />
            )}
            <span className="relative z-10">{t.label}</span>
          </button>
        ))}
      </div>
      <AnimatePresence mode="wait">
        <motion.div
          key={active}
          role="tabpanel"
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -8 }}
          transition={{ duration: 0.3, ease: EASE }}
          className="mj-tabs__panel pt-6"
        >
          {tabs[active].content}
        </motion.div>
      </AnimatePresence>
    </div>
  );
}
