import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { Logo } from '@/components/ui/Logo';
import { INTRO_ENABLED } from '@/lib/config';
import { REDUCED_MOTION, STILL } from '@/lib/motion';
import { EASE } from '@/lib/format';

const KEY = 'mj-intro';

/** ¿Corresponde mostrar la cortina en esta carga? (una vez por sesión de pestaña) */
function shouldPlay() {
  if (!INTRO_ENABLED || REDUCED_MOTION || STILL || typeof window === 'undefined') return false;
  try {
    return !sessionStorage.getItem(KEY);
  } catch {
    return false;
  }
}

/**
 * Cortina de entrada: logo de la tienda sobre el fondo del tema, ~0,8 s en
 * pantalla y se retira hacia arriba. Se activa desde Apariencia → MJ
 * Appearance (o el tema hijo) y respeta prefers-reduced-motion.
 */
export function Intro() {
  const [show, setShow] = useState(shouldPlay);

  useEffect(() => {
    if (!show) return undefined;
    try {
      sessionStorage.setItem(KEY, '1');
    } catch {
      /* sin storage: se mostraría en cada carga, así que no insistimos */
    }
    document.documentElement.classList.add('mj-intro-active');
    const timer = setTimeout(() => setShow(false), 900);
    return () => {
      clearTimeout(timer);
      document.documentElement.classList.remove('mj-intro-active');
    };
  }, [show]);

  return (
    <AnimatePresence>
      {show && (
        <motion.div
          key="mj-intro"
          className="mj-intro fixed inset-0 z-[300] grid place-items-center bg-bg"
          aria-hidden="true"
          initial={{ y: 0 }}
          exit={{ y: '-100%', transition: { duration: 0.65, ease: EASE } }}
        >
          <div className="mj-intro__inner flex flex-col items-center gap-5">
            <motion.div
              initial={{ scale: 0.8, opacity: 0, y: 10 }}
              animate={{ scale: 1, opacity: 1, y: 0 }}
              transition={{ duration: 0.5, ease: EASE }}
            >
              <Logo size="lg" linked={false} className="flex-col gap-4" nameClassName="mj-intro__name display" />
            </motion.div>
            <motion.span
              className="mj-intro__rule h-px w-16 origin-left bg-accent"
              initial={{ scaleX: 0 }}
              animate={{ scaleX: 1 }}
              transition={{ duration: 0.5, delay: 0.25, ease: EASE }}
            />
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
