const IMAGE_TYPES = new Set(['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif']);

export const MAX_BILL_PHOTO_BYTES = 5 * 1024 * 1024;

/**
 * Shrink phone camera photos before upload (target under 5 MB).
 * PDFs and already-small images are returned unchanged.
 */
export async function compressImageForUpload(file, {
    maxBytes = MAX_BILL_PHOTO_BYTES,
} = {}) {
    if (!file || !isCompressibleImage(file)) {
        return file;
    }

    if (file.size > 0 && file.size <= maxBytes) {
        return file;
    }

    try {
        const bitmap = await loadImageBitmap(file);
        const { width: originalWidth, height: originalHeight } = bitmap;
        const edgeSteps = [2000, 1600, 1200, 1000, 800];
        const qualitySteps = [0.82, 0.72, 0.62, 0.52, 0.42];

        let bestBlob = null;

        for (const maxEdge of edgeSteps) {
            const { width, height } = fitWithin(originalWidth, originalHeight, maxEdge);
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                break;
            }
            ctx.drawImage(bitmap, 0, 0, width, height);

            for (const quality of qualitySteps) {
                const blob = await canvasToJpegBlob(canvas, quality);
                if (!blob || blob.size === 0) {
                    continue;
                }
                if (!bestBlob || blob.size < bestBlob.size) {
                    bestBlob = blob;
                }
                if (blob.size <= maxBytes) {
                    bitmap.close?.();
                    return toJpegFile(blob, file.name);
                }
            }
        }

        bitmap.close?.();

        if (bestBlob) {
            return toJpegFile(bestBlob, file.name);
        }

        return file;
    } catch {
        return file;
    }
}

function isCompressibleImage(file) {
    if (IMAGE_TYPES.has(file.type)) {
        return true;
    }
    return /\.(jpe?g|png|webp|heic|heif)$/i.test(file.name || '');
}

function fitWithin(width, height, maxEdge) {
    const longest = Math.max(width, height);
    if (longest <= maxEdge) {
        return { width, height };
    }
    const scale = maxEdge / longest;
    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

function toJpegFile(blob, name) {
    return new File([blob], renameToJpeg(name), { type: 'image/jpeg', lastModified: Date.now() });
}

async function loadImageBitmap(file) {
    if (typeof createImageBitmap === 'function') {
        return createImageBitmap(file);
    }

    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Could not load image'));
        };
        img.src = url;
    });
}

function canvasToJpegBlob(canvas, quality) {
    return new Promise((resolve) => {
        if (canvas.toBlob) {
            canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality);
            return;
        }
        try {
            const dataUrl = canvas.toDataURL('image/jpeg', quality);
            const bytes = atob(dataUrl.split(',')[1] || '');
            const arr = new Uint8Array(bytes.length);
            for (let i = 0; i < bytes.length; i += 1) {
                arr[i] = bytes.charCodeAt(i);
            }
            resolve(new Blob([arr], { type: 'image/jpeg' }));
        } catch {
            resolve(null);
        }
    });
}

function renameToJpeg(name) {
    const base = (name || 'bill-photo').replace(/\.[^.]+$/, '');
    return `${base || 'bill-photo'}.jpg`;
}
