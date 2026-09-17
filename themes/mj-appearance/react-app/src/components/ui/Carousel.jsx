import { Children, useRef } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/format';

/**
 * Carrusel horizontal con scroll nativo (snap) y flechas en desktop.
 *
 * `cols` es cuántos ítems se ven a la vez en pantallas grandes; en el
 * celular se ve uno y un poco del siguiente, para que se note que sigue.
 * El ancho de cada ítem lo pone globals.css (.mj-carousel__item) a partir de
 * --mj-cols, así una skin puede cambiarlo sin tocar el componente.
 */
export function Carousel({ children, cols = 4, className, ariaLabel = 'Carrusel' }) {
  const ref = useRef(null);
  const items = Children.toArray(children);

  const scroll = (dir) => {
    const el = ref.current;
    if (!el) return;
    el.scrollBy({ left: dir * el.clientWidth * 0.9, behavior: 'smooth' });
  };

  const arrowCls =
    'mj-carousel__arrow absolute top-1/2 z-10 hidden size-10 -translate-y-1/2 place-items-center rounded-full glass text-ink shadow-soft transition-colors hover:bg-surface-2 md:grid';

  return (
    <div className={cn('mj-carousel group relative', className)} style={{ '--mj-cols': cols }}>
      <div
        ref={ref}
        role="region"
        aria-label={ariaLabel}
        className="mj-carousel__track no-scrollbar flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth pb-2 md:gap-6"
      >
        {items.map((child, i) => (
          <div key={child.key ?? i} className="mj-carousel__item snap-start shrink-0">
            {child}
          </div>
        ))}
      </div>
      {items.length > cols && (
        <>
          <button type="button" onClick={() => scroll(-1)} className={`${arrowCls} -left-4`} aria-label="Anterior">
            <ChevronLeft size={18} />
          </button>
          <button type="button" onClick={() => scroll(1)} className={`${arrowCls} -right-4`} aria-label="Siguiente">
            <ChevronRight size={18} />
          </button>
        </>
      )}
    </div>
  );
}
