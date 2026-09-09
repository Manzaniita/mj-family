import { Stagger } from '@/components/motion/Reveal';
import { ProductCard } from './ProductCard';
import { cn } from '@/lib/format';

export function ProductGrid({ products, cols = 4, className, highlight }) {
  const colCls = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
  }[cols];

  return (
    <Stagger className={cn('grid grid-cols-1 gap-5 md:gap-6', colCls, className)}>
      {products.map((p) => (
        <ProductCard key={p.id} product={p} highlight={highlight} />
      ))}
    </Stagger>
  );
}
