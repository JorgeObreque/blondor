// Sincronizador con Drive simulado (fotos de site/assets/img). Correr: npm test
import {test, mock} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, readdirSync, mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const IMG = new URL('../../site/assets/img/', import.meta.url).pathname;
let files = [
  {id: 'aaaaaaaaaaaaaaaaaaaa1', name: 'IMG_0001.webp', mimeType: 'image/webp', md5Checksum: 'm1', createdTime: '2026-10-01T00:00:00Z', _f: 'color.webp'},
  {id: 'bbbbbbbbbbbbbbbbbbbb2', name: 'babylights.webp', mimeType: 'image/webp', md5Checksum: 'm2', createdTime: '2026-10-05T00:00:00Z', _f: 'mechas.webp'},
  {id: 'cccccccccccccccccccc3', name: '01 Ondas suaves.webp', mimeType: 'image/webp', md5Checksum: 'm3', createdTime: '2026-09-01T00:00:00Z', _f: 'hero.webp'},
  {id: 'dddddddddddddddddddd4', name: 'brushing.heic', mimeType: 'image/heic', md5Checksum: 'm4', createdTime: '2026-10-03T00:00:00Z', _f: 'brushing.webp'},
  {id: 'eeeeeeeeeeeeeeeeeeee5', name: 'notas.pdf', mimeType: 'application/pdf', createdTime: '2026-10-09T00:00:00Z'},
];
let downloads = 0;
const request = async ({url, params}) => {
  if (url.endsWith('/files')) {
    if (params.q.includes("mimeType='application/vnd.google-apps.folder'")) return {data: {files: [{id: 'X', name: 'Anuncios'}, {id: 'W', name: ' Imágenes '}]}};
    assert.match(params.q, /^'W' in parents/);
    return {data: {files: files.filter((f) => f.mimeType.startsWith('image/'))}};
  }
  if (url.startsWith('https://thumb/')) { downloads++; return {data: readFileSync(IMG + url.split('/').pop().split('=')[0])}; }
  const id = url.split('/').pop();
  const f = files.find((x) => x.id === id);
  if (params.fields === 'thumbnailLink') return {data: {thumbnailLink: `https://thumb/${f._f}=s220`}};
  downloads++;
  return {data: readFileSync(IMG + f._f)};
};
mock.module(import.meta.resolve('google-auth-library'), {namedExports: {GoogleAuth: class { async getClient() { return {request}; } }}});

const OUT = mkdtempSync(join(tmpdir(), 'galeria-'));
process.env.OUT_DIR = OUT;
process.env.GOOGLE_DRIVE_ROOT_FOLDER_ID = 'ROOT';
process.env.GOOGLE_APPLICATION_CREDENTIALS = '/dev/null';
const {main} = await import('./sync.mjs');

test('primera corrida', async () => {
  await main();
  const j = JSON.parse(readFileSync(OUT + '/galeria.json'));
  assert.deepEqual(j.items.map((i) => i.caption), ['Ondas suaves', 'Babylights', 'Brushing', '']);
  assert.equal(downloads, 4);
  for (const i of j.items) { assert.ok(i.w <= 1200 && i.thumbW <= 600 && i.h > 0); assert.match(i.src, /^assets\/galeria\/.+-1200\.webp$/); }
  assert.equal(readdirSync(OUT).filter((n) => n.endsWith('.webp')).length, 8);
});

test('segunda corrida no descarga; quitar una foto la borra', async () => {
  files = files.filter((f) => f.name !== 'babylights.webp');
  await main();
  assert.equal(downloads, 4);
  assert.equal(readdirSync(OUT).filter((n) => n.endsWith('.webp')).length, 6);
});

test('cambiar una foto la regenera', async () => {
  files[0].md5Checksum = 'm1b';
  await main();
  assert.equal(downloads, 5);
  assert.equal(readdirSync(OUT).filter((n) => n.endsWith('.webp')).length, 6);
});
