/**
 * Сцена конфигуратора: реальный участок двора с выбранной плиткой,
 * цветом и раскладкой. Плитка рисуется одним InstancedMesh — даже
 * несколько тысяч элементов не роняют FPS.
 *
 * Размер элемента берётся из характеристик каталога ERP (specs.size),
 * поэтому раскладка на экране соответствует реальному изделию.
 */
import {
    AmbientLight,
    BoxGeometry,
    Color,
    DirectionalLight,
    Fog,
    Group,
    InstancedMesh,
    Matrix4,
    Mesh,
    MeshStandardMaterial,
    Object3D,
    PCFSoftShadowMap,
    PerspectiveCamera,
    PlaneGeometry,
    RepeatWrapping,
    SRGBColorSpace,
    Scene,
    TextureLoader,
    WebGLRenderer,
} from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';

const TILE_THICKNESS = 0.06;

/** «300 × 300 × 60 мм» → { w: 0.3, l: 0.3 } в метрах. */
export function parseTileSize(size, fallback = { w: 0.3, l: 0.3 }) {
    if (!size) return fallback;
    const nums = String(size).match(/\d+([.,]\d+)?/g);
    if (!nums || nums.length < 2) return fallback;
    const [a, b] = nums.map((n) => parseFloat(n.replace(',', '.')) / 1000);
    return a > 0 && b > 0 ? { w: a, l: b } : fallback;
}

/**
 * Позиции элементов для раскладки. Возвращает массив {x, z, rot}.
 * Координаты — от центра участка.
 */
export function layout(pattern, area, tile) {
    const items = [];
    const { width, length } = area;
    const gap = 0.006; // шов
    const w = tile.w + gap;
    const l = tile.l + gap;
    const halfW = width / 2;
    const halfL = length / 2;

    const push = (x, z, rot = 0) => {
        if (Math.abs(x) <= halfW + w && Math.abs(z) <= halfL + l) items.push({ x, z, rot });
    };

    if (pattern === 'herringbone') {
        // Ёлочка: пары элементов под 90°, шаг по диагонали.
        const step = w + l;
        for (let i = -Math.ceil(width / step) - 2; i <= Math.ceil(width / step) + 2; i++) {
            for (let j = -Math.ceil(length / l) - 2; j <= Math.ceil(length / l) + 2; j++) {
                const baseX = i * step + (j % 2 ? step / 2 : 0);
                const baseZ = j * (w + l) / 2;
                push(baseX - halfW + w / 2, baseZ - halfL, 0);
                push(baseX - halfW + w + l / 2, baseZ - halfL + (l - w) / 2, Math.PI / 2);
            }
        }
        return items.filter((p) => Math.abs(p.x) <= halfW && Math.abs(p.z) <= halfL);
    }

    if (pattern === 'basket') {
        // Плетёнка: квадраты из двух пар, повёрнутых на 90°.
        const block = Math.max(w, l * 2);
        for (let i = 0; i * block < width + block; i++) {
            for (let j = 0; j * block < length + block; j++) {
                const x = -halfW + i * block;
                const z = -halfL + j * block;
                const rotated = (i + j) % 2 === 0;
                if (rotated) {
                    push(x + l / 2, z + block / 4, Math.PI / 2);
                    push(x + l * 1.5, z + block / 4, Math.PI / 2);
                } else {
                    push(x + block / 4, z + w / 2, 0);
                    push(x + block / 4, z + w * 1.5, 0);
                }
            }
        }
        return items;
    }

    // running (со смещением) и stack (шов в шов)
    const cols = Math.ceil(width / w) + 1;
    const rows = Math.ceil(length / l) + 1;
    for (let r = 0; r < rows; r++) {
        const offset = pattern === 'running' && r % 2 ? w / 2 : 0;
        for (let c = 0; c < cols; c++) {
            push(-halfW + c * w + w / 2 - offset, -halfL + r * l + l / 2);
        }
    }
    return items;
}

export function createConfiguratorScene(canvas) {
    const renderer = new WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = PCFSoftShadowMap;

    const scene = new Scene();
    scene.fog = new Fog(0x0a0c0f, 24, 70);

    const camera = new PerspectiveCamera(42, 1, 0.1, 200);
    camera.position.set(7, 7, 10);

    const controls = new OrbitControls(camera, canvas);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.minDistance = 4;
    controls.maxDistance = 30;
    controls.maxPolarAngle = Math.PI / 2.15;
    controls.target.set(0, 0, 0);

    scene.add(new AmbientLight(0xffffff, 0.6));
    const sun = new DirectionalLight(0xfff3e0, 2.2);
    sun.position.set(9, 16, 7);
    sun.castShadow = true;
    sun.shadow.mapSize.set(1024, 1024);
    sun.shadow.camera.left = -18;
    sun.shadow.camera.right = 18;
    sun.shadow.camera.top = 18;
    sun.shadow.camera.bottom = -18;
    scene.add(sun);

    const ground = new Mesh(
        new PlaneGeometry(120, 120),
        new MeshStandardMaterial({ color: 0x0b0d10, roughness: 0.95 }),
    );
    ground.rotation.x = -Math.PI / 2;
    ground.position.y = -0.02;
    ground.receiveShadow = true;
    scene.add(ground);

    // Газон вокруг площадки — даёт масштаб и «двор», а не плитку в пустоте.
    const lawn = new Mesh(
        new PlaneGeometry(1, 1),
        new MeshStandardMaterial({ color: 0x2f4a3c, roughness: 1 }),
    );
    lawn.rotation.x = -Math.PI / 2;
    lawn.position.y = -0.015;
    lawn.receiveShadow = true;
    scene.add(lawn);

    const tileMaterial = new MeshStandardMaterial({ color: new Color('#C8B79A'), roughness: 0.7, metalness: 0.02 });
    const curbMaterial = new MeshStandardMaterial({ color: new Color('#9AA1A6'), roughness: 0.85 });

    const group = new Group();
    scene.add(group);

    // Фото изделия из каталога становится текстурой плитки: конфигуратор
    // показывает настоящую поверхность, а не абстрактный цвет.
    const textureLoader = new TextureLoader();
    const textureCache = new Map();
    let onTextureLoaded = () => {};

    const applyTexture = (material, url) => {
        if (!url) {
            material.map = null;
            material.needsUpdate = true;
            return;
        }
        const cached = textureCache.get(url);
        if (cached) {
            material.map = cached;
            material.color.set(0xffffff);
            material.needsUpdate = true;
            return;
        }
        textureLoader.load(url, (map) => {
            map.colorSpace = SRGBColorSpace;
            map.wrapS = RepeatWrapping;
            map.wrapT = RepeatWrapping;
            map.anisotropy = renderer.capabilities.getMaxAnisotropy();
            textureCache.set(url, map);
            material.map = map;
            material.color.set(0xffffff);
            material.needsUpdate = true;
            onTextureLoaded();
        });
    };

    let tiles = null;
    let curbs = null;
    const dummy = new Object3D();

    const clearMesh = (mesh) => {
        if (!mesh) return;
        group.remove(mesh);
        mesh.geometry.dispose();
    };

    /** Пересобрать участок под новые параметры. */
    const build = ({ tileSize, pattern, area, color, withCurb = true, texture = null, curbTexture = null }) => {
        // Без фото — красим цветом из палитры карточки; с фото — фото главнее.
        tileMaterial.color.set(texture ? 0xffffff : color);
        applyTexture(tileMaterial, texture);
        applyTexture(curbMaterial, curbTexture);

        clearMesh(tiles);
        clearMesh(curbs);

        const positions = layout(pattern, area, tileSize);
        const geometry = new BoxGeometry(tileSize.w, TILE_THICKNESS, tileSize.l);
        tiles = new InstancedMesh(geometry, tileMaterial, positions.length || 1);
        tiles.castShadow = true;
        tiles.receiveShadow = true;

        positions.forEach((p, i) => {
            dummy.position.set(p.x, TILE_THICKNESS / 2, p.z);
            dummy.rotation.set(0, p.rot, 0);
            dummy.updateMatrix();
            tiles.setMatrixAt(i, dummy.matrix);
        });
        tiles.instanceMatrix.needsUpdate = true;
        group.add(tiles);

        // Бордюр по периметру
        if (withCurb) {
            const curbGeo = new BoxGeometry(1, 0.16, 0.12);
            const halfW = area.width / 2;
            const halfL = area.length / 2;
            const segments = [];
            const stepX = 1;
            for (let x = -halfW; x < halfW; x += stepX) {
                segments.push({ x: x + stepX / 2, z: -halfL - 0.08, rot: 0 });
                segments.push({ x: x + stepX / 2, z: halfL + 0.08, rot: 0 });
            }
            for (let z = -halfL; z < halfL; z += stepX) {
                segments.push({ x: -halfW - 0.08, z: z + stepX / 2, rot: Math.PI / 2 });
                segments.push({ x: halfW + 0.08, z: z + stepX / 2, rot: Math.PI / 2 });
            }

            curbs = new InstancedMesh(curbGeo, curbMaterial, segments.length || 1);
            curbs.castShadow = true;
            curbs.receiveShadow = true;
            segments.forEach((s, i) => {
                dummy.position.set(s.x, 0.06, s.z);
                dummy.rotation.set(0, s.rot, 0);
                dummy.updateMatrix();
                curbs.setMatrixAt(i, dummy.matrix);
            });
            curbs.instanceMatrix.needsUpdate = true;
            group.add(curbs);
        }

        lawn.scale.set(area.width + 10, area.length + 10, 1);

        // Камера всегда охватывает участок целиком.
        const span = Math.max(area.width, area.length);
        controls.maxDistance = Math.max(20, span * 2.4);
        camera.position.set(span * 0.7, span * 0.62, span * 0.95);
        controls.target.set(0, 0, 0);
        controls.update();

        return positions.length;
    };

    const resize = () => {
        const parent = canvas.parentElement;
        const width = parent?.clientWidth || 640;
        const height = parent?.clientHeight || 420;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    };

    let rafId = null;
    const loop = () => {
        controls.update();
        renderer.render(scene, camera);
        rafId = requestAnimationFrame(loop);
    };

    resize();
    window.addEventListener('resize', resize, { passive: true });

    const visibility = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting && !rafId) loop();
        else if (!entry.isIntersecting && rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
    }, { threshold: 0.01 });
    visibility.observe(canvas);

    loop();

    return {
        build,
        resize,
        resetView: () => {
            controls.reset();
            controls.update();
        },
        dispose() {
            visibility.disconnect();
            if (rafId) cancelAnimationFrame(rafId);
            window.removeEventListener('resize', resize);
            clearMesh(tiles);
            clearMesh(curbs);
            ground.geometry.dispose();
            lawn.geometry.dispose();
            tileMaterial.dispose();
            curbMaterial.dispose();
            controls.dispose();
            renderer.dispose();
        },
    };
}
