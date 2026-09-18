import { AnimatePresence, motion } from 'framer-motion';
import { ShoppingBag } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Price } from '@/components/ui/Price';
import { ProductImage } from '@/components/ui/ProductImage';
import { EASE } from '@/lib/format';
import { t } from '@/lib/copy';

/**
 * Barra de compra fija (solo mobile) que aparece cuando el botón principal
 * de la ficha sale de pantalla: foto, nombre, precio y el CTA. Se apoya
 * sobre la navegación inferior (offset en .mj-buybar, globals.css).
 */
export function MobileBuyBar({ product, priceProduct, visible, label, disabled, onClick }) {
  return (
    <AnimatePresence>
      {visible && (
        <motion.div
          className="mj-buybar fixed inset-x-0 z-70 border-t border-line bg-bg/90 px-4 py-3 backdrop-blur-2xl lg:hidden"
          initial={{ y: 72, opacity: 0 }}
          animate={{ y: 0, opacity: 1 }}
          exit={{ y: 72, opacity: 0 }}
          transition={{ duration: 0.35, ease: EASE }}
          role="region"
          aria-label={t('product_bar_label')}
        >
          <div className="flex items-center gap-3">
            <ProductImage product={product} className="size-11 shrink-0 rounded-(--mj-radius-md)" />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium leading-tight">{product.name}</p>
              <Price product={priceProduct} size="sm" />
            </div>
            <Button size="sm" disabled={disabled} onClick={onClick} className="shrink-0">
              <ShoppingBag size={15} />
              {label}
            </Button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
