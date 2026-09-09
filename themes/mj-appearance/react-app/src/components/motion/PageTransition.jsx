import { motion } from 'framer-motion';
import { EASE } from '@/lib/format';

/**
 * Transición entre rutas: la página entrante sube con un fundido; la saliente
 * se desvanece más rápido para que la navegación se sienta ágil. Framer deja
 * `transform: none` al terminar, así los elementos fixed de cada página
 * (lightbox, hojas de filtros, barra de compra) no quedan atrapados.
 */
export function PageTransition({ children }) {
  return (
    <motion.div
      className="mj-page"
      initial={{ opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0, transition: { duration: 0.45, ease: EASE } }}
      exit={{ opacity: 0, y: -8, transition: { duration: 0.22, ease: EASE } }}
    >
      {children}
    </motion.div>
  );
}
