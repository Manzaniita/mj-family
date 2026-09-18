import { useNavigate } from 'react-router-dom';
import { ShoppingBag, ArrowRight, HandHeart } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Price } from '@/components/ui/Price';
import { Rating } from '@/components/ui/Rating';
import { ProductImage } from '@/components/ui/ProductImage';
import { useCart, useUI } from '@/store';
import { discountPct } from '@/lib/api';
import { REVIEWS_ENABLED } from '@/lib/config';
import { COLEGAS, colegaOf } from '@/lib/colegas';
import { t, tf } from '@/lib/copy';

export function QuickView() {
  const { quickView, setQuickView, setCartOpen } = useUI();
  const add = useCart((s) => s.add);
  const navigate = useNavigate();
  const p = quickView;
  const colega = p ? colegaOf(p) : null;

  return (
    <Modal open={!!p} onClose={() => setQuickView(null)} wide label={p ? tf('product_quick_view_aria', { nombre: p.name }) : undefined}>
      {p && (
        <div className="mj-quickview grid md:grid-cols-2">
          <ProductImage product={p} className="aspect-square md:aspect-auto md:min-h-105" />
          <div className="flex flex-col justify-center gap-5 p-8 md:p-10">
            <div className="flex flex-wrap gap-2">
              {p.is_new && <Badge tone="solid">{t('product_badge_new')}</Badge>}
              {discountPct(p) > 0 && <Badge tone="danger">-{discountPct(p)}%</Badge>}
              <Badge tone={p.stock_status === 'instock' ? 'success' : 'neutral'}>
                {p.stock_status === 'instock' ? t('product_in_stock') : t('product_out_of_stock')}
              </Badge>
            </div>
            <div>
              <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-ink-faint">{p.brandName ?? p.brand ?? p.category}</p>
              <h2 className="display mt-2 text-3xl">{p.name}</h2>
              {/* quién lo hizo (plugin MJ Colegas) */}
              {colega && (
                <p className="mj-maker-line mt-2 flex items-center gap-1.5 text-xs text-ink-soft">
                  <HandHeart size={13} className="text-accent" />
                  {COLEGAS.labels.madeBy} <span className="font-medium text-ink">{colega.name}</span>
                </p>
              )}
              <p className="mt-2 text-ink-soft">{p.tagline}</p>
              {p.sku && (
                <p className="mj-sku mt-2 text-xs text-ink-faint">
                  {t('product_sku')} <span className="font-mono text-ink-soft">{p.sku}</span>
                </p>
              )}
            </div>
            {REVIEWS_ENABLED && <Rating value={p.rating} count={p.rating_count} />}
            <Price product={p} size="lg" />
            <div className="flex flex-wrap gap-3">
              <Button
                disabled={p.stock_status !== 'instock'}
                onClick={() => {
                  setQuickView(null);
                  if (p.type === 'variable') {
                    navigate(`/producto/${p.slug}`);
                    return;
                  }
                  add(p);
                  setCartOpen(true);
                }}
              >
                <ShoppingBag size={16} /> {p.type === 'variable' ? t('product_choose_options') : t('product_add_to_cart')}
              </Button>
              <Button
                variant="secondary"
                onClick={() => { setQuickView(null); navigate(`/producto/${p.slug}`); }}
              >
                {t('product_view_detail')} <ArrowRight size={15} />
              </Button>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}
