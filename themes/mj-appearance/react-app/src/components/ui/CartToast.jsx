import { AnimatePresence, motion } from 'framer-motion';
import { AlertCircle } from 'lucide-react';
import { useCart } from '@/store';
import { EASE } from '@/lib/format';

/**
 * Toast flotante con los errores del carrito (stock, límites, red).
 * Lee useCart.error — se auto-oculta porque el store limpia el mensaje.
 * Lo monta el Layout.
 */
export function CartToast() {
  const error = useCart((s) => s.error);

  return (
    <AnimatePresence>
      {error && (
        <motion.div
          key="cart-toast"
          role="alert"
          initial={{ opacity: 0, y: 24, scale: 0.96 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          exit={{ opacity: 0, y: 12, scale: 0.96 }}
          transition={{ duration: 0.35, ease: EASE }}
          className="fixed bottom-24 right-6 z-[200] flex max-w-sm items-start gap-3 rounded-xl2 glass border border-danger/30 bg-danger/10 px-4 py-3 text-danger shadow-xl lg:bottom-6"
        >
          <AlertCircle size={18} strokeWidth={1.75} className="mt-0.5 shrink-0" />
          <p className="text-sm font-medium leading-snug">{error}</p>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
