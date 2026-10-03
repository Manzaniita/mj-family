import assert from 'node:assert/strict';
import { test } from 'node:test';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import React from 'react';
import { create } from 'react-test-renderer';

const mocks = {
  '@/lib/config': 'export const SITE = { name: "Tienda de Prueba" };',
  '@/lib/format': 'export const cn = (...args) => args.filter(Boolean).join(" ");',
};

const bundle = await build({
  entryPoints: [
    resolve('src/components/ui/ProductImage.jsx'),
    resolve('src/lib/wc.js'),
  ],
  bundle: true,
  write: false,
  outdir: 'out',
  format: 'cjs',
  platform: 'node',
  jsx: 'automatic',
  packages: 'external',
  alias: { '@': resolve('src') },
  plugins: [{
    name: 'mocks-focal',
    setup(api) {
      api.onResolve({ filter: /^@\/lib\/(config|format)$/ }, args => ({ path: args.path, namespace: 'mock' }));
      api.onLoad({ filter: /.*/, namespace: 'mock' }, args => ({ contents: mocks[args.path], loader: 'js', resolveDir: resolve('.') }));
    }
  }],
});

const req = createRequire(import.meta.url);

function loadModule(fileIndex) {
  const mod = { exports: {} };
  new Function('require', 'module', 'exports', bundle.outputFiles[fileIndex].text)(req, mod, mod.exports);
  return mod.exports;
}

const { ProductImage } = loadModule(0);
const { mapProduct } = loadModule(1);

test('mapProduct extrae focal principal y galería desde extensions.mj', () => {
  const rawProduct = {
    id: 101,
    name: 'Bufanda Artesanal',
    slug: 'bufanda-artesanal',
    prices: { price: '500000', regular_price: '500000', currency_minor_unit: 2 },
    images: [
      { id: 11, src: 'https://ejemplo.com/foto1.jpg', alt: 'Principal' },
      { id: 12, src: 'https://ejemplo.com/foto2.jpg', alt: 'Detalle' },
    ],
    extensions: {
      mj: {
        focal: { x: 0.5, y: 0.2 },
        gallery_focal: {
          12: { x: 0.8, y: 0.4 },
        },
      },
    },
  };

  const mapped = mapProduct(rawProduct);
  assert.deepEqual(mapped.focal, { x: 0.5, y: 0.2 });
  assert.deepEqual(mapped.imageSet.focal, { x: 0.5, y: 0.2 });
  assert.equal(mapped.galleryImages.length, 2);
  assert.deepEqual(mapped.galleryImages[0].focal, { x: 0.5, y: 0.2 });
  assert.deepEqual(mapped.galleryImages[1].focal, { x: 0.8, y: 0.4 });
});

test('ProductImage aplica objectPosition según el punto de enfoque', () => {
  const product = {
    name: 'Gorro Lana',
    image: 'https://ejemplo.com/gorro.jpg',
    focal: { x: 0.5, y: 0.15 },
  };

  const renderer = create(React.createElement(ProductImage, { product }));
  const root = renderer.toJSON();
  const img = root.children.find(child => child.type === 'img');

  assert.ok(img, 'Debe renderizar un elemento img');
  assert.equal(img.props.style?.objectPosition, '50% 15%');
  assert.ok(img.props.className.includes('object-cover'));
});

test('ProductImage usa 50% 50% / sin style si no hay focal point definido', () => {
  const product = {
    name: 'Almohadón',
    image: 'https://ejemplo.com/almohadon.jpg',
  };

  const renderer = create(React.createElement(ProductImage, { product }));
  const root = renderer.toJSON();
  const img = root.children.find(child => child.type === 'img');

  assert.ok(img, 'Debe renderizar un elemento img');
  assert.equal(img.props.style?.objectPosition, undefined);
});
