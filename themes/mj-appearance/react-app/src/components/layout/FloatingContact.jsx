import { useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import {
  MessageCircle, ShoppingBag, Heart, Scale, Store, Package, FileDown, ArrowLeft,
} from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { useCart, useWishlist, useCompare, useCatalog, useUI } from '@/store';
import { WHATSAPP_NUMBERS, SITE } from '@/lib/config';
import { buildMessage, cartLines, cartTotal, productLines, whatsappUrl } from '@/lib/consult';
import { downloadQuotePdf, cartRows, productRows } from '@/lib/pdf';
import { EASE } from '@/lib/format';
import { t as tCopy, tf, tn } from '@/lib/copy';

/**
 * Botón flotante de consultas por WhatsApp.
 * Los temas se arman según el contexto y solo se muestran los que aplican:
 * producto abierto / tienda en general / carrito / favoritos / comparador.
 * Se configura en Apariencia → MJ Appearance (vendedores: "Nombre | Número").
 */
export function FloatingContact() {
  const [open, setOpen] = useState(false);
  const [topicId, setTopicId] = useState(null);
  const [sellerIdx, setSellerIdx] = useState(null);
  const [pdfBusy, setPdfBusy] = useState(false);

  const currentProduct = useUI((s) => s.currentProduct);
  const cartItems = useCart((s) => s.items);
  const wishIds = useWishlist((s) => s.ids);
  const compareIds = useCompare((s) => s.ids);
  const catalogProducts = useCatalog((s) => s.products);

  const topics = useMemo(() => {
    const list = [];
    if (currentProduct) {
      list.push({
        id: 'product',
        icon: Package,
        label: tCopy('consult_topic_product'),
        description: currentProduct.name,
        lines: () => productLines([currentProduct]),
        rows: () => productRows([currentProduct]),
        total: null,
        title: tf('consult_msg_product', { tienda: SITE.name }),
      });
    } else {
      list.push({
        id: 'store',
        icon: Store,
        label: tCopy('consult_topic_store'),
        description: tCopy('consult_topic_store_hint'),
        lines: () => [],
        rows: null,
        total: null,
        title: tf('consult_msg_store', { tienda: SITE.name }),
      });
    }
    if (cartItems.length > 0) {
      list.push({
        id: 'cart',
        icon: ShoppingBag,
        label: tCopy('consult_topic_cart'),
        description: tn('shop_count', cartItems.length),
        lines: () => cartLines(cartItems),
        rows: () => cartRows(cartItems),
        total: () => cartTotal(cartItems),
        title: tCopy('consult_msg_cart'),
      });
    }
    const wished = catalogProducts.filter((p) => wishIds.includes(p.id));
    if (wished.length > 0) {
      list.push({
        id: 'wishlist',
        icon: Heart,
        label: tCopy('consult_topic_wishlist'),
        description: tn('shop_count', wished.length),
        lines: () => productLines(wished),
        rows: () => productRows(wished),
        total: null,
        title: tCopy('consult_msg_wishlist'),
      });
    }
    const compared = catalogProducts.filter((p) => compareIds.includes(p.id));
    if (compared.length > 0) {
      list.push({
        id: 'compare',
        icon: Scale,
        label: tCopy('consult_topic_compare'),
        description: tn('shop_count', compared.length),
        lines: () => productLines(compared),
        rows: () => productRows(compared),
        total: null,
        title: tCopy('consult_msg_compare'),
      });
    }
    return list;
  }, [currentProduct, cartItems, wishIds, compareIds, catalogProducts]);

  if (WHATSAPP_NUMBERS.length === 0) return null;

  const topic = topics.find((t) => t.id === topicId) ?? null;
  const seller = sellerIdx != null ? WHATSAPP_NUMBERS[sellerIdx] : null;

  const close = () => {
    setOpen(false);
    setTopicId(null);
    setSellerIdx(null);
  };

  const pickTopic = (t) => {
    setTopicId(t.id);
    if (WHATSAPP_NUMBERS.length === 1) setSellerIdx(0);
  };

  const message = topic
    ? buildMessage({
        title: topic.title,
        lines: topic.lines(),
        total: typeof topic.total === 'function' ? topic.total() : topic.total,
      })
    : '';

  const handlePdf = async () => {
    if (!topic?.rows) return;
    setPdfBusy(true);
    try {
      await downloadQuotePdf({
        title: tf('consult_pdf_title', { tema: topic.label }),
        rows: topic.rows(),
        total: typeof topic.total === 'function' ? topic.total() : topic.total,
        filename: `consulta-${topic.id}.pdf`,
      });
    } finally {
      setPdfBusy(false);
    }
  };

  return (
    <>
      <motion.button
        onClick={() => setOpen(true)}
        className="mj-whatsapp fixed bottom-24 right-6 z-[80] grid size-14 place-items-center rounded-full bg-[#25D366] text-white shadow-soft transition-transform hover:scale-105 lg:bottom-6"
        initial={{ opacity: 0, scale: 0.6, y: 16 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        transition={{ duration: 0.5, ease: EASE, delay: 0.6 }}
        aria-label={tCopy('consult_whatsapp')}
      >
        <MessageCircle size={24} />
      </motion.button>

      <Modal open={open} onClose={close}>
        <div className="p-7">
          {!topic && (
            <>
              <h2 className="font-display text-2xl font-semibold tracking-tight">{tCopy('consult_topic_title')}</h2>
              <div className="mt-6 space-y-3">
                {topics.map((t) => {
                  const Icon = t.icon;
                  return (
                    <button
                      key={t.id}
                      onClick={() => pickTopic(t)}
                      className="mj-choice flex w-full items-center gap-4 rounded-xl2 border border-line p-4 text-left transition-colors hover:border-accent hover:bg-accent-soft"
                    >
                      <span className="grid size-10 shrink-0 place-items-center rounded-full bg-surface-2 text-accent">
                        <Icon size={18} />
                      </span>
                      <span className="min-w-0">
                        <span className="block font-medium">{t.label}</span>
                        <span className="block truncate text-sm text-ink-faint">{t.description}</span>
                      </span>
                    </button>
                  );
                })}
              </div>
            </>
          )}

          {topic && !seller && (
            <>
              <h2 className="font-display text-2xl font-semibold tracking-tight">{tCopy('consult_seller_title')}</h2>
              <div className="mt-6 space-y-3">
                {WHATSAPP_NUMBERS.map((s, i) => (
                  <button
                    key={s.number}
                    onClick={() => setSellerIdx(i)}
                    className="mj-choice flex w-full items-center gap-4 rounded-xl2 border border-line p-4 text-left transition-colors hover:border-accent hover:bg-accent-soft"
                  >
                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-[#25D366]/15 text-[#25D366]">
                      <MessageCircle size={18} />
                    </span>
                    <span className="min-w-0">
                      <span className="block font-medium">{s.name || tCopy('consult_seller')}</span>
                      <span className="block truncate text-sm text-ink-faint">+{s.number}</span>
                    </span>
                  </button>
                ))}
              </div>
              <StepBack onClick={() => setTopicId(null)} />
            </>
          )}

          {topic && seller && (
            <>
              <h2 className="font-display text-2xl font-semibold tracking-tight">
                {topic.label} · {seller.name || tCopy('consult_seller')}
              </h2>
              <div className="mt-4 max-h-48 overflow-y-auto rounded-xl2 border border-line p-4">
                <pre className="whitespace-pre-wrap font-sans text-sm text-ink-soft">{message}</pre>
              </div>
              <div className="mt-6 space-y-3">
                <button
                  className="mj-btn inline-flex h-13 w-full items-center justify-center gap-2.5 rounded-(--mj-radius-btn) bg-[#25D366] px-8 font-medium text-white transition-all duration-300 hover:bg-[#25D366]/90"
                  onClick={() => window.open(whatsappUrl(seller.number, message), '_blank', 'noopener')}
                >
                  <MessageCircle size={16} /> {tCopy('consult_whatsapp')}
                </button>
                {topic.rows && (
                  <Button className="w-full" variant="secondary" onClick={handlePdf} disabled={pdfBusy}>
                    <FileDown size={16} /> {pdfBusy ? tCopy('consult_pdf_busy') : tCopy('consult_pdf')}
                  </Button>
                )}
              </div>
              <StepBack onClick={() => (WHATSAPP_NUMBERS.length === 1 ? setTopicId(null) : setSellerIdx(null))} />
            </>
          )}
        </div>
      </Modal>
    </>
  );
}

function StepBack({ onClick }) {
  return (
    <button
      onClick={onClick}
      className="mt-5 inline-flex items-center gap-1.5 text-sm text-ink-faint transition-colors hover:text-ink"
    >
      <ArrowLeft size={14} /> {tCopy('consult_back')}
    </button>
  );
}
