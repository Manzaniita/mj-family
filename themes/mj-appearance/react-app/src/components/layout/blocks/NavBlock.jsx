import { MegaMenu } from '@/components/layout/MegaMenu';

export function NavBlock({ style = 'inline' }) {
  return (
    <nav className="mj-block-nav hidden lg:flex items-center">
      <MegaMenu inline={style === 'inline'} />
    </nav>
  );
}

export function MegaMenuBlock({ inline = false }) {
  return (
    <div className="mj-block-megamenu w-full">
      <MegaMenu inline={inline} />
    </div>
  );
}
