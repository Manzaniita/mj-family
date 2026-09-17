import { Stagger } from '@/components/motion/Reveal';
import { ProductCard } from './ProductCard';
import { cn } from '@/lib/format';

const colClasses = {
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-2 lg:grid-cols-3',
  4: 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
};

/** Grilla gris con la forma de las cards, para mientras llega el catálogo. */
export function ProductGridSkeleton({ count = 4, cols = 4, className }) {
  return (
    <div className={cn('grid grid-cols-1 gap-5 md:gap-6', colClasses[cols], className)} aria-hidden="true">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="mj-card-skeleton overflow-hidden rounded-xl3 border border-line bg-bg-soft">
          <div className="aspect-square animate-pulse bg-surface-2" />
          <div className="space-y-3 p-4 md:p-5">
            <div className="h-3 w-1/3 animate-pulse rounded bg-surface-2" />
            <div className="h-4 w-4/5 animate-pulse rounded bg-surface-2" />
            <div className="h-5 w-1/2 animate-pulse rounded bg-surface-2" />
            <div className="h-10 animate-pulse rounded-(--mj-radius-btn) bg-surface-2" />
          </div>
        </div>
      ))}
    </div>
  );
}

/** `loading`: muestra el esqueleto en lugar de la grilla (mismo alto: nada salta). */
export function ProductGrid({ products, cols = 4, className, highlight, loading = false, skeleton = 4 }) {
  const colCls = colClasses[cols];
  if (loading) return <ProductGridSkeleton count={skeleton} cols={cols} className={className} />;

  return (
    <Stagger className={cn('grid grid-cols-1 gap-5 md:gap-6', colCls, className)}>
      {products.map((p) => (
        <ProductCard key={p.id} product={p} highlight={highlight} />
      ))}
    </Stagger>
  );
}
