import { ExchangeRate } from '@/components/layout/ExchangeRate';
import { PaymentSelector } from '@/components/layout/PaymentSelector';
import { TopBar } from '@/components/layout/TopBar';
import { FreeShippingBar } from '@/components/layout/FreeShippingBar';
import { Button } from '@/components/ui/Button';
import { iconByName } from '@/components/ui/DynamicIcon';
import { ArrowUpRight } from 'lucide-react';
import { isExternalHref } from '@/lib/nav';

export function DolarBlock({ compact = true }) {
  return (
    <div className="mj-block-dolar flex items-center">
      <ExchangeRate className="mr-1.5" />
    </div>
  );
}

export function PaymentSelectorBlock({ variant = 'pill' }) {
  return (
    <div className="mj-block-payments flex items-center">
      <PaymentSelector className="mr-1.5 hidden sm:inline-flex" />
    </div>
  );
}

export function CtaButtonBlock({ label = 'Ver más', url = '#', variant = 'secondary', size = 'sm', icon, target = '_self' }) {
  const Icon = icon ? iconByName(icon, ArrowUpRight) : null;
  const isExt = isExternalHref(url) || target === '_blank';

  const content = (
    <>
      {Icon && <Icon size={15} strokeWidth={1.8} className="mr-1" />}
      <span>{label}</span>
    </>
  );

  const props = {
    size,
    variant,
    className: 'mj-block-cta px-3',
    title: label,
    'aria-label': label,
  };

  return (
    <div className="mj-block-cta-wrapper flex items-center">
      {isExt ? (
        <Button {...props} href={url} target={target === '_blank' ? '_blank' : undefined} rel={target === '_blank' ? 'noreferrer' : undefined}>
          {content}
        </Button>
      ) : (
        <Button {...props} to={url}>
          {content}
        </Button>
      )}
    </div>
  );
}

export function HtmlBlock({ content = '', textColor, fontSize }) {
  if (!content) return null;

  return (
    <div
      className="mj-block-html text-sm"
      style={{
        color: textColor || undefined,
        fontSize: fontSize || undefined,
      }}
      dangerouslySetInnerHTML={{ __html: content }}
    />
  );
}

export function TopBarBlock() {
  return (
    <div className="mj-block-topbar w-full">
      <TopBar />
    </div>
  );
}

export function ShippingBarBlock() {
  return (
    <div className="mj-block-shipping-bar w-full">
      <FreeShippingBar />
    </div>
  );
}
