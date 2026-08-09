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
    ACESFilmicToneMapping,
    AmbientLight,
    BoxGeometry,
    CylinderGeometry,
    Color,
    DirectionalLight,
    Fog,
    Group,
    IcosahedronGeometry,
    LatheGeometry,
    Mesh,
    MeshStandardMaterial,
    PCFSoftShadowMap,
    PMREMGenerator,
    PerspectiveCamera,
    PlaneGeometry,
    Scene,
    SpotLight,
    RepeatWrapping,
    SRGBColorSpace,
    TextureLoader,
    Vector2,
    Vector3,
    WebGLRenderer,
} from 'three';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';
import { loadModel } from './gltf';

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
    // Тонемаппинг переводит «пересвеченный» линейный свет в фотографичную
    // картинку: без него бетон выглядит пластиковым.
    renderer.toneMapping = ACESFilmicToneMapping;
    renderer.toneMappingExposure = 0.82;

    const scene = new Scene();
    scene.fog = new Fog(0x08090b, 30, 86);

    // Освещение по окружению (IBL): материалы получают мягкие отражения и
    // полутени, как в студии. RoomEnvironment генерируется на лету —
    // внешних HDRI-файлов качать не нужно.
    const pmrem = new PMREMGenerator(renderer);
    pmrem.compileEquirectangularShader();
    const environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    scene.environment = environment;
    // Студийная карта яркая: на полной силе она вымывает цвет композита в белый.
    scene.environmentIntensity = 0.35;

    const camera = new PerspectiveCamera(38, 1, 0.1, 200);
    camera.position.set(0, 9, 20);

    // --- Свет: студийный, с одним «солнцем» и мягкой заливкой ---
    // Окружение уже даёт заливку, поэтому ambient — только лёгкий подмес.
    scene.add(new AmbientLight(0xffffff, 0.22));

    const sun = new DirectionalLight(0xfff4e2, 1.55);
    sun.position.set(12, 22, 10);
    sun.castShadow = true;
    sun.shadow.mapSize.set(2048, 2048);
    // Мягкий край тени вместо «вырезанной ножницами» границы.
    sun.shadow.radius = 4;
    sun.shadow.bias = -0.0006;
    sun.shadow.camera.near = 1;
    sun.shadow.camera.far = 70;
    sun.shadow.camera.left = -26;
    sun.shadow.camera.right = 26;
    sun.shadow.camera.top = 26;
    sun.shadow.camera.bottom = -26;
    scene.add(sun);

    const rim = new SpotLight(0xc8b79a, 40, 60, 0.6, 0.7, 1.4);
    rim.position.set(-16, 14, -12);
    scene.add(rim);

    // --- Основание: тёмная площадка под двором ---
    const ground = new Mesh(
        new PlaneGeometry(160, 160),
        new MeshStandardMaterial({ color: 0x16181c, roughness: 1, metalness: 0, envMapIntensity: 0.35 }),
    );
    ground.rotation.x = -Math.PI / 2;
    ground.position.y = -0.12;
    ground.receiveShadow = true;
    scene.add(ground);

    const world = new Group();
    scene.add(world);

    // Основание под покрытием — песчано-цементная подушка. Без неё щели между
    // элементами выглядели чёрными провалами: под плиткой была пустота.
    const bedding = new Mesh(
        new PlaneGeometry(19.6, 9.2),
        new MeshStandardMaterial({ color: 0x4a453d, roughness: 1, metalness: 0, envMapIntensity: 0.2 }),
    );
    bedding.rotation.x = -Math.PI / 2;
    bedding.position.y = -0.09;
    bedding.receiveShadow = true;
    world.add(bedding);

    // Материалы переиспользуются всеми мешами — меньше draw-call'ов.
    // Шлифованный композит — матовый с лёгким блеском; дерево мягче,
    // зелень полностью матовая. envMapIntensity подмешивает окружение.
    const materials = {
        paving: new MeshStandardMaterial({ color: palette.paving, roughness: 0.58, metalness: 0.04, envMapIntensity: 0.4 }),
        pavingAlt: new MeshStandardMaterial({ color: palette.paving.clone().multiplyScalar(0.8), roughness: 0.62, metalness: 0.04, envMapIntensity: 0.4 }),
        curb: new MeshStandardMaterial({ color: palette.curb, roughness: 0.72, metalness: 0.03, envMapIntensity: 0.35 }),
        wood: new MeshStandardMaterial({ color: palette.wood, roughness: 0.45, metalness: 0, envMapIntensity: 0.45 }),
        green: new MeshStandardMaterial({ color: palette.green, roughness: 1, metalness: 0, envMapIntensity: 0.4 }),
    };

    // --- Фото-текстуры из каталога ERP ---
    // Если у карточки отмечено фото «3D», им красится изделие в сцене;
    // пока фото нет, остаётся ровный цвет — сцена работает в обоих случаях.
    const textureLoader = new TextureLoader();
    const applyTexture = (material, url, repeat = 1) => {
        if (!url) return;
        textureLoader.load(url, (map) => {
            map.colorSpace = SRGBColorSpace;
            map.wrapS = RepeatWrapping;
            map.wrapT = RepeatWrapping;
            map.repeat.set(repeat, repeat);
            map.anisotropy = renderer.capabilities.getMaxAnisotropy();
            material.map = map;
            // Цвет фото уже несёт оттенок изделия — базовый тон делаем белым,
            // иначе текстура «уходит» в песочный.
            material.color.set(0xffffff);
            material.needsUpdate = true;
            needsRender = true;
        });
    };

    const setTextures = (textures = {}) => {
        applyTexture(materials.paving, textures.paving);
        applyTexture(materials.pavingAlt, textures.paving);
        applyTexture(materials.curb, textures.curb);
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
    // Шов ~2 % от размера элемента: на реальной укладке это 3–5 мм.
    // Раньше зазор был 10 %, из-за чего покрытие выглядело развалившимся.
    const tileGeo = new BoxGeometry(1.94, 0.2, 0.96);
    const cols = 9;
    const rows = 8;
    let index = 0;
    for (let r = 0; r < rows; r++) {
        const shift = r % 2 ? -0.5 : 0.5;
        for (let c = 0; c < cols; c++) {
            const x = (c - (cols - 1) / 2) * 2 + shift;
            const z = (r - (rows - 1) / 2) * 1.0;
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
    // Полуширина покрытия с учётом ½-сдвига перевязки.
    const halfX = ((cols - 1) * 2) / 2 + 1.5;
    const halfZ = (rows * 1.0) / 2;
    const curbSide = new BoxGeometry(halfX * 2 + 0.6, 0.42, 0.3);
    const curbEnd = new BoxGeometry(0.3, 0.42, halfZ * 2);

    [-1, 1].forEach((side) => {
        const bar = new Mesh(curbSide, materials.curb);
        bar.position.set(0, 0.11, side * (halfZ + 0.15));
        addPart(bar, { from: 0.5, to: 0.74, offset: new Vector3(0, 0, side * 12) });
    });
    [-1, 1].forEach((side) => {
        const bar = new Mesh(curbEnd, materials.curb);
        bar.position.set(side * (halfX + 0.15), 0.11, 0);
        addPart(bar, { from: 0.54, to: 0.78, offset: new Vector3(side * 12, 0, 0) });
    });

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
    // Профиль вазона: ножка → расширение → бортик. Точки идут снизу вверх,
    // радиус меняется нелинейно — силуэт получается «чашей», а не конусом.
    const vaseProfile = [
        new Vector2(0.0, 0.0), new Vector2(0.42, 0.0), new Vector2(0.44, 0.06),
        new Vector2(0.40, 0.14), new Vector2(0.52, 0.42), new Vector2(0.70, 0.82),
        new Vector2(0.86, 1.18), new Vector2(0.94, 1.34), new Vector2(0.96, 1.42),
        new Vector2(0.90, 1.44), new Vector2(0.88, 1.30),
    ];
    const vaseGeo = new LatheGeometry(vaseProfile, 48);
    [
        { pos: new Vector3(5.6, 0, -2.2), from: 0.74 },
        { pos: new Vector3(5.6, 0, 1.4), from: 0.78 },
    ].forEach(({ pos, from }) => {
        const group = new Group();
        const vase = new Mesh(vaseGeo, materials.paving);
        vase.castShadow = true;
        group.add(vase);
        group.scale.setScalar(0.72);
        // Куст собираем из нескольких сфер — силуэт живой, а не конус.
        [[0, 1.72, 0, 0.62], [0.34, 1.58, 0.2, 0.42], [-0.3, 1.62, -0.16, 0.38], [0.08, 1.94, -0.22, 0.34]]
            .forEach(([bx, by, bz, r]) => {
                const leaf = new Mesh(new IcosahedronGeometry(r, 1), materials.green);
                leaf.position.set(bx, by, bz);
                leaf.castShadow = true;
                group.add(leaf);
            });
        group.position.copy(pos);
        group.userData.slot = 'vase';
        addPart(group, { from, to: from + 0.16, offset: new Vector3(0, 11, 0), spin: 0.8 });
    });

    // --- 5. Урна: рядом со скамьёй ---
    const urn = new Group();
    const urnBody = new Mesh(new CylinderGeometry(0.46, 0.4, 1.16, 32), materials.paving);
    urnBody.castShadow = true;
    urn.add(urnBody);
    // Бортик сверху — по нему читается, что это урна, а не столбик.
    const urnRim = new Mesh(new CylinderGeometry(0.5, 0.5, 0.1, 32), materials.curb);
    urnRim.position.y = 0.6;
    urnRim.castShadow = true;
    urn.add(urnRim);
    urn.position.set(-2.6, 0.64, -2.4);
    addPart(urn, { from: 0.8, to: 0.94, offset: new Vector3(0, 9, 0), spin: 1.6 });

    // --- Камера: пролёт от крупного плана к общему виду двора ---
    const cameraPath = [
        { at: 0.0, pos: new Vector3(2.6, 1.5, 5.2), look: new Vector3(0, 0.4, 0) },
        { at: 0.3, pos: new Vector3(0.5, 6.5, 12), look: new Vector3(0, 0.2, 0) },
        { at: 0.62, pos: new Vector3(-7.5, 6.5, 12), look: new Vector3(0, 0.4, 0) },
        { at: 1.0, pos: new Vector3(0.5, 8.5, 15), look: new Vector3(0, 0.3, 0) },
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

    setTextures(options.textures ?? {});
    loop();
    options.onReady?.();

    return {
        setTextures,
        /**
         * Подменить процедурную скамью/вазон/урну настоящими GLB-моделями,
         * если они загружены в карточке товара.
         */
        async setModels(models = {}) {
            const slots = [
                ['bench', bench, 1],
                ['vase', world.children.find((o) => o.userData.slot === 'vase'), 1],
            ];
            for (const [key, target, scale] of slots) {
                if (!models[key] || !target) continue;
                const model = await loadModel(models[key], scale);
                if (!model) continue;
                model.position.copy(target.position);
                model.rotation.copy(target.rotation);
                model.userData = { ...target.userData };
                world.remove(target);
                world.add(model);
                const i = parts.indexOf(target);
                if (i !== -1) parts[i] = model;
                needsRender = true;
            }
        },
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
