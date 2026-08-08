/**
 * Двор QAZAQ TAS в 3D. Сцена собирается по мере скролла: одна плитка →
 * раскладка → бордюры → малые формы → готовый двор.
 *
 * Геометрия процедурная (никаких тяжёлых GLB): считается один раз, дальше
 * двигаются только матрицы — сцена держит 60 FPS и на слабых ноутбуках.
 *
 * Наружу отдаём минимальный API: setProgress / setColor / resize / dispose.
 */
import {
    AmbientLight,
    BoxGeometry,
    CylinderGeometry,
    Color,
    DirectionalLight,
    Fog,
    Group,
    LatheGeometry,
    Mesh,
    MeshStandardMaterial,
    PCFSoftShadowMap,
    PerspectiveCamera,
    PlaneGeometry,
    Scene,
    SpotLight,
    Vector2,
    Vector3,
    WebGLRenderer,
} from 'three';

const easeOut = (t) => 1 - Math.pow(1 - t, 3);
const clamp01 = (t) => Math.min(1, Math.max(0, t));

/** Прогресс внутри окна [from, to] — 0 до окна, 1 после. */
const stage = (p, from, to) => clamp01((p - from) / (to - from));

export function createCourtyard(canvas, options = {}) {
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    const palette = {
        paving: new Color(options.color ?? '#C8B79A'),
        accent: new Color('#8A8D91'),
        curb: new Color('#9AA1A6'),
        wood: new Color('#8A6B4A'),
        green: new Color('#4A6B5B'),
    };

    const renderer = new WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = PCFSoftShadowMap;

    const scene = new Scene();
    scene.fog = new Fog(0x08090b, 26, 78);

    const camera = new PerspectiveCamera(38, 1, 0.1, 200);
    camera.position.set(0, 9, 20);

    // --- Свет: студийный, с одним «солнцем» и мягкой заливкой ---
    scene.add(new AmbientLight(0xffffff, 0.55));

    const sun = new DirectionalLight(0xfff4e2, 2.1);
    sun.position.set(12, 22, 10);
    sun.castShadow = true;
    sun.shadow.mapSize.set(1024, 1024);
    sun.shadow.camera.near = 1;
    sun.shadow.camera.far = 70;
    sun.shadow.camera.left = -26;
    sun.shadow.camera.right = 26;
    sun.shadow.camera.top = 26;
    sun.shadow.camera.bottom = -26;
    scene.add(sun);

    const rim = new SpotLight(0xc8b79a, 60, 60, 0.6, 0.7, 1.4);
    rim.position.set(-16, 14, -12);
    scene.add(rim);

    // --- Основание: тёмная площадка под двором ---
    const ground = new Mesh(
        new PlaneGeometry(120, 120),
        new MeshStandardMaterial({ color: 0x0b0d10, roughness: 0.95, metalness: 0 }),
    );
    ground.rotation.x = -Math.PI / 2;
    ground.position.y = -0.12;
    ground.receiveShadow = true;
    scene.add(ground);

    const world = new Group();
    scene.add(world);

    // Материалы переиспользуются всеми мешами — меньше draw-call'ов.
    const materials = {
        paving: new MeshStandardMaterial({ color: palette.paving, roughness: 0.72, metalness: 0.02 }),
        pavingAlt: new MeshStandardMaterial({ color: palette.paving.clone().multiplyScalar(0.82), roughness: 0.74 }),
        curb: new MeshStandardMaterial({ color: palette.curb, roughness: 0.8 }),
        wood: new MeshStandardMaterial({ color: palette.wood, roughness: 0.6 }),
        green: new MeshStandardMaterial({ color: palette.green, roughness: 0.9 }),
    };

    /** Элемент сцены: у каждого своё окно появления и стартовое смещение. */
    const parts = [];
    const addPart = (mesh, { from, to, offset, spin = 0 }) => {
        mesh.userData.target = mesh.position.clone();
        mesh.userData.offset = offset;
        mesh.userData.from = from;
        mesh.userData.to = to;
        mesh.userData.spin = spin;
        mesh.castShadow = true;
        mesh.receiveShadow = true;
        world.add(mesh);
        parts.push(mesh);
        return mesh;
    };

    // --- 1. Раскладка плитки со смещением (running bond) ---
    const tileGeo = new BoxGeometry(1.9, 0.22, 0.95);
    const cols = 8;
    const rows = 8;
    let index = 0;
    for (let r = 0; r < rows; r++) {
        const shift = r % 2 ? -0.95 : 0;
        for (let c = 0; c < cols; c++) {
            const x = (c - (cols - 1) / 2) * 2 + shift;
            const z = (r - (rows - 1) / 2) * 1.05;
            const mesh = new Mesh(tileGeo, index % 6 === 0 ? materials.pavingAlt : materials.paving);
            mesh.position.set(x, 0, z);
            // Центральные плитки ложатся первыми — рисунок растёт от середины.
            const distance = Math.hypot(x, z) / 12;
            const from = 0.16 + distance * 0.34;
            addPart(mesh, {
                from,
                to: Math.min(from + 0.2, 0.82),
                offset: new Vector3((Math.random() - 0.5) * 14, 9 + Math.random() * 7, (Math.random() - 0.5) * 14),
                spin: (Math.random() - 0.5) * 2.4,
            });
            index++;
        }
    }

    // --- 2. Бордюры: обрамляют площадку с четырёх сторон ---
    const curbGeo = new BoxGeometry(2, 0.5, 0.32);
    const halfX = (cols * 2) / 2;
    const halfZ = (rows * 1.05) / 2;
    for (let i = 0; i < cols; i++) {
        const x = (i - (cols - 1) / 2) * 2;
        [-1, 1].forEach((side) => {
            const mesh = new Mesh(curbGeo, materials.curb);
            mesh.position.set(x, 0.14, side * (halfZ + 0.2));
            addPart(mesh, {
                from: 0.5 + i * 0.012,
                to: 0.74,
                offset: new Vector3(0, 0, side * 12),
            });
        });
    }
    for (let i = 0; i < rows; i++) {
        const z = (i - (rows - 1) / 2) * 1.05;
        [-1, 1].forEach((side) => {
            const mesh = new Mesh(curbGeo, materials.curb);
            mesh.position.set(side * (halfX + 0.2), 0.14, z);
            mesh.rotation.y = Math.PI / 2;
            addPart(mesh, {
                from: 0.54 + i * 0.012,
                to: 0.78,
                offset: new Vector3(side * 12, 0, 0),
            });
        });
    }

    // --- 3. Скамья: две опоры из композита + деревянные ламели ---
    const bench = new Group();
    const legGeo = new BoxGeometry(0.36, 0.86, 1.5);
    [-1.5, 1.5].forEach((x) => {
        const leg = new Mesh(legGeo, materials.paving);
        leg.position.set(x, 0.43, 0);
        leg.castShadow = true;
        bench.add(leg);
    });
    const slatGeo = new BoxGeometry(3.8, 0.11, 0.26);
    for (let i = 0; i < 5; i++) {
        const slat = new Mesh(slatGeo, materials.wood);
        slat.position.set(0, 0.9, -0.6 + i * 0.3);
        slat.castShadow = true;
        bench.add(slat);
    }
    bench.position.set(-5.4, 0.12, -2.2);
    bench.rotation.y = 0.35;
    addPart(bench, { from: 0.72, to: 0.9, offset: new Vector3(0, 10, 0), spin: 1.2 });

    // --- 4. Вазоны: тело вращения + «зелень» ---
    const vaseProfile = [];
    for (let i = 0; i <= 12; i++) {
        const t = i / 12;
        vaseProfile.push(new Vector2(0.55 + t * 0.55, t * 1.35));
    }
    const vaseGeo = new LatheGeometry(vaseProfile, 32);
    [
        { pos: new Vector3(6.2, 0, -2.6), from: 0.74 },
        { pos: new Vector3(6.2, 0, 1.6), from: 0.78 },
    ].forEach(({ pos, from }) => {
        const group = new Group();
        const vase = new Mesh(vaseGeo, materials.paving);
        vase.castShadow = true;
        group.add(vase);
        const bush = new Mesh(new CylinderGeometry(0.86, 0.2, 1.1, 12), materials.green);
        bush.position.y = 1.85;
        bush.castShadow = true;
        group.add(bush);
        group.position.copy(pos);
        addPart(group, { from, to: from + 0.16, offset: new Vector3(0, 11, 0), spin: 0.8 });
    });

    // --- 5. Урна: рядом со скамьёй ---
    const urn = new Mesh(new CylinderGeometry(0.5, 0.38, 1.2, 20), materials.curb);
    urn.position.set(-2.6, 0.72, -2.4);
    addPart(urn, { from: 0.8, to: 0.94, offset: new Vector3(0, 9, 0), spin: 1.6 });

    // --- Камера: пролёт от крупного плана к общему виду двора ---
    const cameraPath = [
        { at: 0.0, pos: new Vector3(2.6, 1.5, 5.2), look: new Vector3(0, 0.4, 0) },
        { at: 0.3, pos: new Vector3(0.5, 6.5, 12), look: new Vector3(0, 0.2, 0) },
        { at: 0.62, pos: new Vector3(-8, 8.5, 14), look: new Vector3(0, 0.4, 0) },
        { at: 1.0, pos: new Vector3(0, 13.5, 22), look: new Vector3(0, 0.6, -0.5) },
    ];

    const lookAt = new Vector3();
    const tmpA = new Vector3();
    const tmpB = new Vector3();

    const moveCamera = (p) => {
        let i = 0;
        while (i < cameraPath.length - 2 && p > cameraPath[i + 1].at) i++;
        const a = cameraPath[i];
        const b = cameraPath[i + 1];
        const t = easeOut(clamp01((p - a.at) / (b.at - a.at)));
        camera.position.copy(tmpA.copy(a.pos).lerp(b.pos, t));
        lookAt.copy(tmpB.copy(a.look).lerp(b.look, t));
        camera.lookAt(lookAt);
    };

    let progress = 0;
    let pointer = { x: 0, y: 0 };
    let needsRender = true;

    const apply = () => {
        for (const part of parts) {
            const t = easeOut(stage(progress, part.userData.from, part.userData.to));
            const target = part.userData.target;
            const offset = part.userData.offset;
            part.position.set(
                target.x + offset.x * (1 - t),
                target.y + offset.y * (1 - t),
                target.z + offset.z * (1 - t),
            );
            if (part.userData.spin) {
                part.rotation.x = part.userData.spin * (1 - t);
            }
            part.visible = t > 0.001;
        }

        // Первая плитка живёт отдельно: медленно вращается в начале истории.
        const hero = parts[Math.floor((rows * cols) / 2 + cols / 2)];
        if (hero) {
            const intro = 1 - clamp01(progress / 0.18);
            hero.visible = true;
            hero.rotation.y = intro * Math.PI * 0.6;
            hero.position.y = hero.userData.target.y + intro * 0.8;
        }

        moveCamera(progress);

        // Лёгкий параллакс от курсора — сцена «живая», но без укачивания.
        camera.position.x += pointer.x * 0.9;
        camera.position.y += pointer.y * 0.5;
        camera.lookAt(lookAt);
    };

    const render = () => {
        if (!needsRender) return;
        apply();
        renderer.render(scene, camera);
        needsRender = false;
    };

    let rafId = null;
    const loop = () => {
        render();
        rafId = requestAnimationFrame(loop);
    };

    const resize = () => {
        const parent = canvas.parentElement;
        const width = parent?.clientWidth || window.innerWidth;
        const height = parent?.clientHeight || window.innerHeight;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        needsRender = true;
    };

    const onPointerMove = (e) => {
        if (reduced) return;
        pointer.x = (e.clientX / window.innerWidth - 0.5) * 2;
        pointer.y = (e.clientY / window.innerHeight - 0.5) * -2;
        needsRender = true;
    };

    resize();
    window.addEventListener('resize', resize, { passive: true });
    window.addEventListener('pointermove', onPointerMove, { passive: true });

    // Сцена простаивает, когда её не видно: не жжём батарею на других экранах.
    const visibility = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
            if (!rafId) loop();
        } else if (rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
    }, { threshold: 0.01 });
    visibility.observe(canvas);

    loop();
    options.onReady?.();

    return {
        setProgress(value) {
            progress = clamp01(value);
            needsRender = true;
        },
        setColor(hex) {
            materials.paving.color.set(hex);
            materials.pavingAlt.color.set(new Color(hex).multiplyScalar(0.82));
            needsRender = true;
        },
        resize,
        dispose() {
            visibility.disconnect();
            if (rafId) cancelAnimationFrame(rafId);
            window.removeEventListener('resize', resize);
            window.removeEventListener('pointermove', onPointerMove);
            scene.traverse((obj) => {
                if (obj.isMesh) {
                    obj.geometry?.dispose?.();
                }
            });
            Object.values(materials).forEach((m) => m.dispose());
            renderer.dispose();
        },
    };
}
