import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { ChevronRight, ArrowUpRight, Heart, Scale } from 'lucide-react';
import { Drawer } from '@/components/ui/Drawer';
import { PaymentSelector } from './PaymentSelector';
import { useUI, useWishlist, useCompare } from '@/store';
import { useCatalog, buildMenu } from '@/store/catalog';
import { NAV_HEADER, isExternalHref } from '@/lib/nav';
import { iconByName } from '@/components/ui/DynamicIcon';
import { t } from '@/lib/copy';

export function MobileMenu() {
  const { mobileMenuOpen, setMobileMenuOpen } = useUI();
  const wishCount = useWishlist((s) => s.ids.length);
  const compareCount = useCompare((s) => s.ids.length);
  const { categories, brands } = useCatalog();
  const menu = useMemo(() => buildMenu({ categories, brands }), [categories, brands]);
  const close = () => setMobileMenuOpen(false);

  const rowClass = 'flex items-center justify-between rounded-xl px-3 py-3.5 text-base font-medium transition-colors hover:bg-surface';

  /** Un ítem del menú: enlace del router, o <a> si el destino sale de la tienda. */
  const renderItem = (item) => {
    const href = item.type === 'category' ? `/categoria/${item.slug}` : item.href;
    const className = `${rowClass} ${item.highlight ? 'text-accent' : ''}`;

    if (isExternalHref(href)) {
      return (
        <a
          key={item.key || item.label}
          href={href}
          target={item.target === '_blank' ? '_blank' : undefined}
          rel={item.target === '_blank' ? 'noreferrer' : undefined}
          onClick={close}
          className={className}
        >
          {item.label}
          <ArrowUpRight size={16} className="text-ink-faint" />
        </a>
      );
    }

    return (
      <Link key={item.key || item.label} to={href} onClick={close} className={className}>
        {item.label}
        <ChevronRight size={16} className="text-ink-faint" />
      </Link>
    );
  };

  return (
    <Drawer open={mobileMenuOpen} onClose={close} title={t('nav_menu')} side="left">
      {/*
        Accesos rápidos (Apariencia → MJ Appearance → Barra de navegación): en
        desktop son botones del header, y acá arriba del todo. Es donde aparece
        el acceso al ERP para quien puede entrar; al cliente no le llega el ítem.
      */}
      {NAV_HEADER.length > 0 && (
        <div className="mb-4 flex flex-col gap-2">
          {NAV_HEADER.map((item) => {
            const Icon = item.icon ? iconByName(item.icon, ArrowUpRight) : ArrowUpRight;
            const className =
              'flex items-center gap-2.5 rounded-xl border border-line bg-surface px-3 py-3 text-sm font-medium transition-colors hover:border-accent hover:text-accent';
            return isExternalHref(item.href) ? (
              <a
                key={item.key}
                href={item.href}
                target={item.target === '_blank' ? '_blank' : undefined}
                rel={item.target === '_blank' ? 'noreferrer' : undefined}
                onClick={close}
                className={className}
              >
                <Icon size={17} strokeWidth={1.8} />
                {item.label}
              </a>
            ) : (
              <Link key={item.key} to={item.href} onClick={close} className={className}>
                <Icon size={17} strokeWidth={1.8} />
                {item.label}
              </Link>
            );
          })}
        </div>
      )}

      <PaymentSelector variant="block" className="mb-4" />
      <nav className="flex flex-col">{menu.map(renderItem)}</nav>

      {/*
        Favoritos y Comparar: en el celular no entran en la barra de arriba, así que su lugar es
        acá. Con el contador, que es lo que hace que valga la pena abrirlos.
      */}
      <div className="mt-4 flex flex-col border-t border-line pt-4">
        {[
          { to: '/favoritos', label: t('nav_favorites'), icon: Heart, count: wishCount },
          { to: '/comparar', label: t('nav_compare'), icon: Scale, count: compareCount },
        ].map(({ to, label, icon: Icon, count }) => (
          <Link key={to} to={to} onClick={close} className={rowClass}>
            <span className="flex items-center gap-2.5">
              <Icon size={17} strokeWidth={1.8} className="text-ink-faint" />
              {label}
            </span>
            {count > 0 ? (
              <span className="grid min-w-5 place-items-center rounded-full bg-accent px-1.5 text-[11px] font-bold text-white">
                {count}
              </span>
            ) : (
              <ChevronRight size={16} className="text-ink-faint" />
            )}
          </Link>
        ))}
      </div>
    </Drawer>
  );
}
