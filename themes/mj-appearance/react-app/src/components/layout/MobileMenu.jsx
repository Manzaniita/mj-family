import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';
import { Drawer } from '@/components/ui/Drawer';
import { PaymentSelector } from './PaymentSelector';
import { useUI } from '@/store';
import { useCatalog, buildMenu } from '@/store/catalog';

export function MobileMenu() {
  const { mobileMenuOpen, setMobileMenuOpen } = useUI();
  const { categories, brands } = useCatalog();
  const menu = useMemo(() => buildMenu({ categories, brands }), [categories, brands]);
  const close = () => setMobileMenuOpen(false);

  return (
    <Drawer open={mobileMenuOpen} onClose={close} title="Menú" side="left">
      <PaymentSelector variant="block" className="mb-4" />
      <nav className="flex flex-col">
        {menu.map((item) => (
          <Link
            key={item.label}
            to={item.type === 'link' ? item.href : `/categoria/${item.slug}`}
            onClick={close}
            className={`flex items-center justify-between rounded-xl px-3 py-3.5 text-base font-medium transition-colors hover:bg-surface ${
              item.highlight ? 'text-accent' : ''
            }`}
          >
            {item.label}
            <ChevronRight size={16} className="text-ink-faint" />
          </Link>
        ))}
      </nav>
    </Drawer>
  );
}
