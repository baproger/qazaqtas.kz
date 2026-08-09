/**
 * Загрузка 3D-моделей из карточки каталога. Поддерживаются два формата:
 *
 *  - GLB/GLTF — один самодостаточный файл: геометрия, материалы и текстуры
 *    внутри. Предпочтительный вариант: грузится быстрее и всегда цветной.
 *  - OBJ — геометрия отдельно, материалы в соседнем .mtl, текстуры — в файлах,
 *    на которые .mtl ссылается по имени. Ищем .mtl рядом с .obj (то же имя,
 *    та же папка): комплект загружается в одну директорию, поэтому ссылки
 *    внутри .mtl разрешаются сами.
 *
 * Модель необязательна: если её нет или файл битый — возвращаем null,
 * и сцена рисует процедурную геометрию. Витрина не падает никогда.
 */
import { Box3, Vector3 } from 'three';

const loaders = {};

const getLoader = async (kind) => {
    if (!loaders[kind]) {
        loaders[kind] = (async () => {
            if (kind === 'gltf') {
                const { GLTFLoader } = await import('three/examples/jsm/loaders/GLTFLoader.js');
                return new GLTFLoader();
            }
            if (kind === 'obj') {
                const { OBJLoader } = await import('three/examples/jsm/loaders/OBJLoader.js');
                return new OBJLoader();
            }
            const { MTLLoader } = await import('three/examples/jsm/loaders/MTLLoader.js');
            return new MTLLoader();
        })();
    }
    return loaders[kind];
};

/** OBJ + материалы: .mtl ищем по тому же имени рядом с .obj. */
async function loadObj(url) {
    const objLoader = await getLoader('obj');
    const mtlUrl = url.replace(/\.obj$/i, '.mtl');

    try {
        const mtlLoader = await getLoader('mtl');
        // Текстуры из .mtl лежат в той же папке — задаём её базовым путём.
        mtlLoader.setResourcePath(url.slice(0, url.lastIndexOf('/') + 1));
        const materials = await mtlLoader.loadAsync(mtlUrl);
        materials.preload();
        objLoader.setMaterials(materials);
    } catch {
        // .mtl нет — модель отрисуется геометрией без материалов (серой).
        objLoader.setMaterials(null);
    }

    return objLoader.loadAsync(url);
}

/**
 * Готовая к сцене модель: отцентрована по низу, вписана в заданную высоту
 * (в метрах), с тенями. При любой ошибке — null, без падения сцены.
 */
export async function loadModel(url, targetHeight = 1) {
    if (!url) return null;

    try {
        const isObj = /\.obj$/i.test(url);
        const model = isObj
            ? await loadObj(url)
            : (await (await getLoader('gltf')).loadAsync(url)).scene;

        const box = new Box3().setFromObject(model);
        const size = new Vector3();
        const center = new Vector3();
        box.getSize(size);
        box.getCenter(center);

        if (size.y > 0 && targetHeight > 0) {
            model.scale.multiplyScalar(targetHeight / size.y);
        }
        // Ставим модель «на пол» и по центру своей опоры.
        model.position.sub(center.multiplyScalar(model.scale.y)).setY(0);

        model.traverse((node) => {
            if (node.isMesh) {
                node.castShadow = true;
                node.receiveShadow = true;
            }
        });

        return model;
    } catch (error) {
        console.warn('Не удалось загрузить 3D-модель', url, error);
        return null;
    }
}
