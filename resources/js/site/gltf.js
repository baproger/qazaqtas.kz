/**
 * Загрузка GLB-моделей из карточки каталога. Модель необязательна: если её
 * нет, сцены рисуют процедурную геометрию — витрина работает в обоих случаях.
 */
import { Box3, Vector3 } from 'three';

let loaderPromise = null;

const getLoader = async () => {
    if (!loaderPromise) {
        loaderPromise = import('three/examples/jsm/loaders/GLTFLoader.js')
            .then(({ GLTFLoader }) => new GLTFLoader());
    }
    return loaderPromise;
};

/**
 * Вернуть готовую к сцене модель: отцентрованную по низу и вписанную
 * в заданную высоту (метры), с тенями. При ошибке — null, без падения сцены.
 */
export async function loadModel(url, targetHeight = 1) {
    if (!url) return null;

    try {
        const loader = await getLoader();
        const gltf = await loader.loadAsync(url);
        const model = gltf.scene;

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
