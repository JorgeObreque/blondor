/* ----------------------------------------------------------------------------
 * Galería de blondor.cl desde el Drive de Publicador.
 *
 * Lee la subcarpeta «imagenes» (GALERIA_FOLDER_NAME) de la carpeta raíz de Publicador, la misma que usa Publicador,
 * y deja en OUT_DIR:
 *   galeria.json            lo que lee site/assets/js/site.js
 *   <id>-<ver>-1200.webp    foto para el visor
 *   <id>-<ver>-600.webp     miniatura para la grilla
 * Solo procesa lo que cambió. Las fotos se guardan sin EXIF (sin GPS ni datos del teléfono).
 *
 * Orden: las fotos cuyo nombre empieza con un número («01 Balayage miel.jpg») van primero y en ese orden; el resto,
 * de la más nueva a la más antigua. El nombre sin número ni extensión es el pie de foto, salvo nombres de cámara
 * (IMG_1234, PXL_…, WhatsApp Image…), que quedan sin pie.
 *
 * Si algo falla, galeria.json no se toca: el sitio sigue mostrando la última versión buena.
 * ---------------------------------------------------------------------------- */

import {GoogleAuth} from 'google-auth-library';
import sharp from 'sharp';
import {createHash} from 'node:crypto';
import {mkdir, readdir, rename, unlink, writeFile, access} from 'node:fs/promises';
import {join} from 'node:path';

const FOLDER_NAME = (process.env.GALERIA_FOLDER_NAME || 'imagenes').trim();
const OUT_DIR = process.env.OUT_DIR || '/out';
const MAX = Number(process.env.GALERIA_MAX || 40);
const URL_PREFIX = process.env.GALERIA_URL_PREFIX || 'assets/galeria/';

const SIZES = {full: 1200, thumb: 600};
const DIRECT = new Set(['image/jpeg', 'image/png', 'image/webp']);
const VIA_THUMBNAIL = new Set(['image/heic', 'image/heif']); // sharp no decodifica HEVC: Drive entrega un JPEG.
const FOLDER = 'application/vnd.google-apps.folder';
const CAMERA_NAME = /^(img|dsc|dcim|pxl|mvimg|photo|foto|image|imagen|whatsapp|screenshot|captura|snapchat|received)[\s_-]|^[\d\s_.-]+$|^[a-f0-9-]{20,}$/i;

function required(name) {
    const value = process.env[name];
    if (!value) {
        console.error(`Falta la variable ${name}.`);
        process.exit(2);
    }
    return value;
}

let ROOT_ID;
let client;

async function drive(url, params, responseType) {
    client ??= await new GoogleAuth({
        keyFile: required('GOOGLE_APPLICATION_CREDENTIALS'),
        scopes: ['https://www.googleapis.com/auth/drive.readonly'],
    }).getClient();
    const {data} = await client.request({url, params, responseType});
    return data;
}

async function list(q, fields, orderBy) {
    const out = [];
    let pageToken;
    do {
        const data = await drive('https://www.googleapis.com/drive/v3/files', {
            q,
            fields: `nextPageToken,files(${fields})`,
            orderBy,
            pageSize: 200,
            pageToken,
            supportsAllDrives: true,
            includeItemsFromAllDrives: true,
        });
        out.push(...(data.files || []));
        pageToken = data.nextPageToken;
    } while (pageToken);
    return out;
}

async function findFolder() {
    const folders = await list(`'${ROOT_ID}' in parents and mimeType='${FOLDER}' and trashed=false`, 'id,name');
    const plain = (name) => name.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const match = folders.find((f) => plain(f.name || '') === plain(FOLDER_NAME));
    if (!match) {
        const names = folders.map((f) => f.name).join(', ') || '(ninguna)';
        throw new Error(`No existe la carpeta «${FOLDER_NAME}» en la raíz de Publicador. Carpetas: ${names}`);
    }
    return match;
}

/** «01 Balayage_miel.jpg» -> {order: 1, caption: 'Balayage miel'} */
export function parseName(name) {
    const base = name.replace(/\.[a-z0-9]{2,5}$/i, '').trim();
    const m = base.match(/^(\d{1,4})[\s._-]+(.*)$/);
    const order = m ? Number(m[1]) : null;
    const rest = (m ? m[2] : base).replace(/[_]+/g, ' ').replace(/\s+/g, ' ').trim();
    const caption = !rest || CAMERA_NAME.test(rest) || CAMERA_NAME.test(base) ? '' : rest;
    return {order, caption: caption.charAt(0).toUpperCase() + caption.slice(1)};
}

async function original(file) {
    if (DIRECT.has(file.mimeType)) {
        const data = await drive(`https://www.googleapis.com/drive/v3/files/${file.id}`, {alt: 'media', supportsAllDrives: true}, 'arraybuffer');
        return Buffer.from(data);
    }
    const meta = await drive(`https://www.googleapis.com/drive/v3/files/${file.id}`, {fields: 'thumbnailLink', supportsAllDrives: true});
    if (!meta.thumbnailLink) throw new Error(`Drive no tiene vista previa de ${file.name}`);
    const data = await drive(meta.thumbnailLink.replace(/=s\d+$/, `=s${SIZES.full * 2}`), undefined, 'arraybuffer');
    return Buffer.from(data);
}

const exists = (p) => access(p).then(() => true, () => false);

async function render(file, key) {
    const paths = Object.fromEntries(Object.entries(SIZES).map(([k, w]) => [k, `${key}-${w}.webp`]));
    const sizes = {};
    const allThere = (await Promise.all(Object.values(paths).map((p) => exists(join(OUT_DIR, p))))).every(Boolean);

    if (allThere) {
        for (const [k, p] of Object.entries(paths)) {
            const m = await sharp(join(OUT_DIR, p)).metadata();
            sizes[k] = {w: m.width, h: m.height};
        }
    } else {
        const buf = await original(file);
        for (const [k, width] of Object.entries(SIZES)) {
            const tmp = join(OUT_DIR, `.${paths[k]}.tmp`);
            const info = await sharp(buf)
                .rotate()
                .resize({width, height: Math.round(width * 1.6), fit: 'inside', withoutEnlargement: true})
                .webp({quality: k === 'thumb' ? 74 : 80})
                .toFile(tmp);
            await rename(tmp, join(OUT_DIR, paths[k]));
            sizes[k] = {w: info.width, h: info.height};
        }
        console.log(`  nueva: ${file.name}`);
    }
    return {paths, sizes};
}

export async function main() {
    ROOT_ID = required('GOOGLE_DRIVE_ROOT_FOLDER_ID');
    required('GOOGLE_APPLICATION_CREDENTIALS');
    await mkdir(OUT_DIR, {recursive: true});
    const folder = await findFolder();
    const files = (await list(
        `'${folder.id}' in parents and trashed=false and mimeType contains 'image/'`,
        'id,name,mimeType,md5Checksum,modifiedTime,createdTime',
        'createdTime desc',
    )).filter((f) => DIRECT.has(f.mimeType) || VIA_THUMBNAIL.has(f.mimeType));

    const ordered = files
        .map((f) => ({...f, ...parseName(f.name || '')}))
        .sort((a, b) => {
            if (a.order !== null || b.order !== null) {
                if (a.order === null) return 1;
                if (b.order === null) return -1;
                if (a.order !== b.order) return a.order - b.order;
            }
            return (b.createdTime || '').localeCompare(a.createdTime || '');
        })
        .slice(0, MAX);

    console.log(`Carpeta «${folder.name}»: ${files.length} imágenes, se publican ${ordered.length}.`);

    const items = [];
    const keep = new Set(['galeria.json']);
    for (const f of ordered) {
        const version = createHash('sha1').update(f.md5Checksum || f.modifiedTime || '').digest('hex').slice(0, 8);
        const key = `${f.id.slice(0, 16)}-${version}`.replace(/[^A-Za-z0-9_-]/g, '');
        try {
            const {paths, sizes} = await render(f, key);
            Object.values(paths).forEach((p) => keep.add(p));
            items.push({
                src: URL_PREFIX + paths.full,
                w: sizes.full.w,
                h: sizes.full.h,
                thumb: URL_PREFIX + paths.thumb,
                thumbW: sizes.thumb.w,
                caption: f.caption,
                alt: f.caption ? `${f.caption}, trabajo de Blondor` : 'Trabajo de Blondor',
            });
        } catch (error) {
            console.warn(`  se omite ${f.name}: ${error.message}`);
        }
    }

    const json = JSON.stringify({updated: new Date().toISOString(), items}, null, 1);
    await writeFile(join(OUT_DIR, '.galeria.json.tmp'), json);
    await rename(join(OUT_DIR, '.galeria.json.tmp'), join(OUT_DIR, 'galeria.json'));

    for (const name of await readdir(OUT_DIR)) {
        if (!keep.has(name) && (name.endsWith('.webp') || name.endsWith('.tmp'))) {
            await unlink(join(OUT_DIR, name));
            console.log(`  borrada: ${name}`);
        }
    }
    console.log(`galeria.json: ${items.length} fotos.`);
}

if (process.argv[1] && import.meta.url.endsWith(process.argv[1].split('/').pop())) {
    main().catch((error) => {
        console.error(`Error: ${error.message}`);
        process.exit(1);
    });
}
