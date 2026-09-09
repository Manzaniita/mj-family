import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { Check } from 'lucide-react';
import { WP, IS_WP } from '@/lib/config';
import { announcements as demoAnnouncements } from '@/data/catalog';
import { iconByName } from '@/components/ui/DynamicIcon';
import { EASE } from '@/lib/format';

// Anuncios configurables desde PHP con shape { text, color } (color null → default).
// En WordPress solo se muestran los reales (vacío → sin barra);
// standalone mantiene los demo como fallback visual.
const announcements = IS_WP
  ? (Array.isArray(WP?.announcements) ? WP.announcements : [])
  : demoAnnouncements;

/** Nivel 1 — anuncios rotativos, muy fino. */
export function TopBar() {
  const [index, setIndex] = useState(0);

  useEffect(() => {
    if (announcements.length < 2) return undefined;
    const t = setInterval(() => setIndex((i) => (i + 1) % announcements.length), 3800);
    return () => clearInterval(t);
  }, []);

  if (!announcements.length) return null;

  const current = announcements[index];
  const AnnounceIcon = iconByName(current.icon, Check);
  // Fondo sólido: la barra es sticky dentro del header — sin él se vería
  // el contenido a través al scrollear.
  return (
    <div className="mj-topbar relative h-8 overflow-hidden bg-bg hairline-b" aria-live="polite">
      <AnimatePresence mode="wait">
        <motion.p
          key={index}
          initial={{ x: 40, opacity: 0 }}
          animate={{ x: 0, opacity: 1 }}
          exit={{ x: -40, opacity: 0 }}
          transition={{ duration: 0.5, ease: EASE }}
          className="mj-topbar__text flex h-8 items-center justify-center gap-2 text-[11px] font-medium uppercase tracking-[0.18em] text-ink-soft"
          style={current.color ? { color: current.color } : undefined}
        >
          <AnnounceIcon size={12} className={current.color ? undefined : 'text-accent'} />
          {current.text}
        </motion.p>
      </AnimatePresence>
    </div>
  );
}
